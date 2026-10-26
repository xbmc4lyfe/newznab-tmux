<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Mcp\ProjectPathGuard;
use App\Services\Mcp\RepositoryContextCollector;
use App\Services\Mcp\SensitiveValueRedactor;
use App\Services\Monitoring\Collectors\ServiceStatusCollector;
use App\Services\Monitoring\Collectors\TmuxSnapshotCollector;
use App\Services\Nzb\NzbParserService;
use FFI;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;
use ReflectionClass;
use ReflectionMethod;
use RuntimeException;

final class RustMcpPathGuardRepoContextRedactorStatusTmuxCollectorW57ParityTest extends TestCase
{
    /** Class => batch entry point; each also has `*Php()` and `*Native()` variants. */
    private const ENTRIES = [
        ProjectPathGuard::class => 'classifyPathBatch',
        RepositoryContextCollector::class => 'planHistoryBatch',
        SensitiveValueRedactor::class => 'planRedactionBatch',
        ServiceStatusCollector::class => 'planStatusBatch',
        TmuxSnapshotCollector::class => 'planCountKeyBatch',
    ];

    /** Class => Wave 57.2 export. */
    private const SYMBOLS = [
        ProjectPathGuard::class => 'nntmux_w57_path_guard_plan_batch',
        RepositoryContextCollector::class => 'nntmux_w57_repo_history_plan_batch',
        SensitiveValueRedactor::class => 'nntmux_w57_redaction_plan_batch',
        ServiceStatusCollector::class => 'nntmux_w57_service_status_plan_batch',
        TmuxSnapshotCollector::class => 'nntmux_w57_tmux_count_key_plan_batch',
    ];

    /** Class => summary words after the per-item words (the digest included). */
    private const TAIL = [
        ProjectPathGuard::class => 10,
        RepositoryContextCollector::class => 6,
        SensitiveValueRedactor::class => 5,
        ServiceStatusCollector::class => 14,
        TmuxSnapshotCollector::class => 9,
    ];

    /**
     * Common mean/p99 then Uncommon mean/p99 speedups (PHP ns / Rust ns), each the minimum of
     * three runs of test_paired_randomized_common_and_uncommon_benchmarks (seed 57, 60
     * interleaved rounds of 25 pairs per class and workload after 3 warmups, 1500 pooled samples
     * per metric, each side the fastest of 3 back-to-back calls, batches of 320 Common or
     * 320-640 Uncommon items, release Rust, PHP 8.5.12 CLI, Apple Silicon macOS on a shared host
     * at load average 4-10). Timings include dispatch, column checks, packing, the FFI call and
     * unpack('P*'). A class defaults to Rust only when all four reach 1.5x.
     */
    private const QUALIFICATION = [
        ProjectPathGuard::class => [4.986, 4.329, 4.89, 4.379],
        RepositoryContextCollector::class => [5.801, 4.741, 5.519, 4.562],
        SensitiveValueRedactor::class => [4.091, 3.402, 3.965, 3.101],
        ServiceStatusCollector::class => [4.916, 5.213, 4.242, 3.924],
        TmuxSnapshotCollector::class => [5.922, 4.864, 5.829, 4.652],
    ];

    /** Interleaved benchmark rounds; each times PAIRS pairs per class and workload. */
    private const ROUNDS = 60;

    private const PAIRS = 25;

    private const WARMUPS = 3;

    /** Back-to-back calls per side of a pair; the fastest is kept (drops preemption spikes). */
    private const REPEATS = 3;

    /** Batches per class and workload in the randomized parity sweep. */
    private const PARITY_BATCHES = 6;

    private const SOURCE_PATHS = [
        'app/Services/Mcp/ProjectPathGuard.php', 'app/Http/Controllers/Api/ApiController.php', 'app/Console/Commands/TmuxDrain.php',
        'tests/Unit/ExampleTest.php', 'tests/Feature/McpDevelopmentServerTest.php', 'config/nntmux-mcp.php', 'routes/web.php',
        'resources/views/layouts/main.blade.php', 'database/migrations/2024_01_01_000000_create_releases_table.php', 'bootstrap/app.php',
        'README.md', 'composer.json', 'phpunit.xml', '.env.example', 'AGENTS.md',
    ];

    private const SUBJECTS = [
        'Fix release admission race', 'feat(nzb): add Wave 56.2 Rust FFI (#926)', 'Merge branch \'master\' into codex/x', 'chore: bump deps',
        'Revert "Add tmux drain"', 'docs: tweak', 'refactor(search): split drivers',
    ];

    private const KEYS = [
        'name', 'host', 'port', 'DB_HOST', 'password', 'DB_PASSWORD', 'api_key', 'apiKey', 'API-KEY', 'token', 'auth_token', 'secret',
        'client_secret', 'Authorization', 'credentials', 'user', 'path', 'output', 'message', 'pass', 'passwd', 'timeout',
    ];

    private const STATUSES = ['operational', 'degraded', 'partial_outage', 'major_outage', 'maintenance'];

    private const COUNT_KEYS = [
        'processmovies', 'processtv', 'processmusic', 'processbooks', 'processconsole', 'processgames', 'processxxx', 'processnfo',
        'processpar2', 'processrenames', 'collections_table', 'binaries_table', 'parts_table', 'missed_parts_table', 'tv', 'movies',
        'audio', 'books', 'console', 'pc', 'xxx', 'misc', 'total_work', 'work', 'work_available', 'releases', 'predb', 'predb_matched',
        'nfo', 'renamed', 'active_groups', 'all_groups', 'requestid_inprogress', 'requestid_matched', 'pre_matched',
    ];

    public function test_wave_methods_and_constants_exist(): void
    {
        foreach (self::ENTRIES as $class => $entry) {
            $reflection = new ReflectionClass($class);
            foreach (['usesW57RustFfi', 'withW57RustFfi', $entry, $entry.'Php', $entry.'Native'] as $method) {
                self::assertTrue($reflection->hasMethod($method), $class.'::'.$method);
                self::assertTrue($reflection->getMethod($method)->isPublic() && $reflection->getMethod($method)->isStatic(), $class.'::'.$method);
                self::assertSame($class, $reflection->getMethod($method)->getDeclaringClass()->getName(), $class.'::'.$method);
            }
            $state = $reflection->getMethod('w57RustFfiState');
            self::assertTrue($state->isPrivate() && $state->isStatic(), $class);
            self::assertSame(2, $state->getNumberOfParameters(), $class);
            self::assertTrue($reflection->hasConstant('W57_RUST_FFI_DEFAULT'), $class);
            self::assertIsBool($reflection->getConstant('W57_RUST_FFI_DEFAULT'), $class);
            self::assertTrue($reflection->getReflectionConstant('W57_RUST_FFI_DEFAULT')?->isPrivate(), $class);
            self::assertStringContainsString(self::SYMBOLS[$class], (string) $reflection->getConstant('W57_FFI_CDEF'), $class);
            foreach (array_keys($reflection->getStaticProperties()) as $property) {
                self::assertStringNotContainsString('w57', strtolower((string) $property), 'Wave 57 state lives in w57RustFfiState().');
            }
        }
    }

    public function test_defaults_match_recorded_qualification(): void
    {
        foreach (self::QUALIFICATION as $class => $speedups) {
            self::assertCount(4, $speedups, $class);
            self::assertSame(min($speedups) >= 1.5, $this->default($class), $class);
            self::assertSame($this->default($class), $class::usesW57RustFfi(), $class);
            if (min($speedups) < 1.5) {
                self::assertFalse($class::usesW57RustFfi(), $class.' retains PHP by default');
            }
        }
    }

    public function test_with_toggle_scopes_and_restores_routing(): void
    {
        foreach (array_keys(self::ENTRIES) as $class) {
            $default = $this->default($class);
            foreach ([true, false] as $enabled) {
                $seen = $class::withW57RustFfi($enabled, static function () use ($class, $enabled): array {
                    $inner = $class::withW57RustFfi(! $enabled, static fn (): bool => $class::usesW57RustFfi());

                    return [$class::usesW57RustFfi(), $inner];
                });
                self::assertSame([$enabled, ! $enabled], $seen, $class);
                self::assertSame($default, $class::usesW57RustFfi(), $class);
            }
            $thrown = null;
            try {
                $class::withW57RustFfi(! $default, static fn () => throw new RuntimeException('boom'));
            } catch (RuntimeException $e) {
                $thrown = $e->getMessage();
            }
            self::assertSame('boom', $thrown, 'The callback exception must propagate.');
            self::assertSame($default, $class::usesW57RustFfi(), $class);
        }
    }

    public function test_toggles_stay_independent_of_each_other_and_earlier_waves(): void
    {
        foreach (array_keys(self::ENTRIES) as $class) {
            $seen = $class::withW57RustFfi(! $this->default($class), static fn (): array => array_map(
                static fn (string $other): bool => $other::usesW57RustFfi(),
                array_keys(self::ENTRIES),
            ));
            foreach (array_keys(self::ENTRIES) as $i => $other) {
                self::assertSame($other === $class ? ! $this->default($other) : $this->default($other), $seen[$i], $class.' vs '.$other);
            }
        }
        // The Wave 57 toggles leave the earlier switches untouched.
        $earlier = static fn (): array => [ProjectPathGuard::usesRustFfi(), ServiceStatusCollector::usesRustFfi(), TmuxSnapshotCollector::$useRustFfi];
        $before = $earlier();
        ProjectPathGuard::withW57RustFfi(false, static function () use ($earlier, $before): void {
            ServiceStatusCollector::withW57RustFfi(true, static function () use ($earlier, $before): void {
                TmuxSnapshotCollector::withW57RustFfi(true, static function () use ($earlier, $before): void {
                    self::assertSame($before, $earlier());
                });
            });
        });
        self::assertSame($before, $earlier());
    }

    public function test_entry_points_route_through_toggle(): void
    {
        $this->requireBindings();
        $random = new Randomizer(new Mt19937(5700));
        foreach (array_keys(self::ENTRIES) as $class) {
            foreach ([false, true] as $uncommon) {
                $args = $this->fixture($class, $random, $uncommon);
                $php = $this->call($class, 'Php', $args);
                foreach ([true, false] as $native) {
                    self::assertSame($php, $class::withW57RustFfi($native, fn (): array => $this->call($class, '', $args)), $class);
                }
                self::assertSame($php, $this->call($class, 'Native', $args), $class);
            }
        }
    }

    public function test_native_helpers_fall_back_to_php_for_unsupported_input(): void
    {
        $this->requireBindings();
        // NUL bytes, ragged or keyed columns and non-integer values keep the PHP semantics.
        $this->assertParity(ProjectPathGuard::class, [["app/a\0b", 'app/x.php']]);
        $this->assertParity(ProjectPathGuard::class, [['x' => 'app/a.php', 'y' => 'routes/web.php']]);
        $this->assertParity(RepositoryContextCollector::class, [["abc1234\tx\0y", "def5678\tz"]]);
        $this->assertParity(RepositoryContextCollector::class, [[1 => "abc1234\tx"]]);
        $this->assertParity(SensitiveValueRedactor::class, [["pass\0word", 'name'], ['secret-value', 'value']]);
        $this->assertParity(SensitiveValueRedactor::class, [['name', 'host'], ["token=abc\0def", 'x']]);
        $this->assertParity(SensitiveValueRedactor::class, [['name', 'host'], ['password=hunter22']]);
        $this->assertParity(ServiceStatusCollector::class, [["degraded\0", 'operational'], [1, 2]]);
        $this->assertParity(ServiceStatusCollector::class, [['degraded', 'operational'], [1]]);
        $this->assertParity(ServiceStatusCollector::class, [['degraded', 'operational'], [1, '2']]);
        $this->assertParity(ServiceStatusCollector::class, [['degraded', 'operational'], [1.5, 2]]);
        $this->assertParity(ServiceStatusCollector::class, [['degraded', 'operational'], [1 => 5, 2 => 6]]);
        $this->assertParity(TmuxSnapshotCollector::class, [["process\0tv", 'tv'], 1, 2, 3]);
        $this->assertParity(TmuxSnapshotCollector::class, [['a' => 'tv'], 1, 2, 3]);
    }

    public function test_exports_guard_null_short_and_malformed_batches(): void
    {
        $this->requireBindings();
        // [good args, malformed args, words, empty args, empty words] before (out, out_cap).
        $guards = [
            ProjectPathGuard::class => [['app', 3, 1], ["a\0b", 3, 1], 11, [null, 0, 0], 10],
            RepositoryContextCollector::class => [["a\tb", 3, 1], ["a\0b", 3, 1], 7, [null, 0, 0], 6],
            SensitiveValueRedactor::class => [['k', 1, 'v', 1, 1], ['k', 1, "v\0w", 3, 1], 6, [null, 0, null, 0, 0], 5],
            ServiceStatusCollector::class => [['degraded', 8, pack('P', 5), 8, pack('P', 0), 8, 1], ['degraded', 8, pack('P', 5), 8, pack('P', 1), 8, 1], 15,
                [null, 0, null, 0, null, 0, 0], 14],
            TmuxSnapshotCollector::class => [['nfo', 3, 1, 1, 2, 3], ["a\0b", 3, 1, 1, 2, 3], 10, [null, 0, 0, 0, 0, 0], 9],
        ];
        foreach ($guards as $class => [$good, $bad, $words, $empty, $emptyWords]) {
            $ffi = $this->ffi($class);
            $out = $ffi->new('int64_t[32]');
            $symbol = self::SYMBOLS[$class];
            self::assertSame($words, $this->invoke($ffi, $symbol, [...$good, $out, 32]), $class);
            self::assertSame(-1, $this->invoke($ffi, $symbol, [...$good, null, 32]), $class);
            self::assertSame(-$words, $this->invoke($ffi, $symbol, [...$good, $out, $words - 1]), $class);
            self::assertSame(-1, $this->invoke($ffi, $symbol, [...$bad, $out, 32]), $class);
            self::assertSame(-1, $this->invoke($ffi, $symbol, [null, $good[1], ...\array_slice($good, 2), $out, 32]), $class);
            self::assertSame($emptyWords, $this->invoke($ffi, $symbol, [...$empty, $out, 32]), $class);
        }
        // Ragged response-time tables and partial null-index words are refused.
        $status = $this->ffi(ServiceStatusCollector::class);
        $statusOut = $status->new('int64_t[32]');
        $symbol = self::SYMBOLS[ServiceStatusCollector::class];
        self::assertSame(-1, $this->invoke($status, $symbol, ['a', 1, pack('P', 5), 7, null, 0, 1, $statusOut, 32]));
        self::assertSame(-1, $this->invoke($status, $symbol, ['a', 1, pack('P', 5), 8, pack('P', 0), 4, 1, $statusOut, 32]));
        self::assertSame(-1, $this->invoke($status, $symbol, ['a', 1, pack('P', 5), 8, pack('P', -1), 8, 1, $statusOut, 32]));
    }

    public function test_path_guard_plan_parity(): void
    {
        $this->requireBindings();
        $paths = [
            'app/Services/X.php', ' app\\\\Services//X.php ', '', "\t\n", '/etc/passwd', 'C:\\x', '1:/x', 'app/../x', 'app/./x', 'lib/x.php',
            'app/vendor/x', 'app/vendor', 'README.md', '.env.example', 'app/.ENV', 'app/.env.local', 'config/Private_Key.php', 'tests/a.pem',
            "tests/a.pem\n/b", "tests/a.pem\n\n/b", 'app/private__key',
        ];
        $words = [
            $this->word(0, 'app/Services/X.php'), $this->word(0, 'app/Services/X.php'), $this->word(1, ''), $this->word(1, ''),
            $this->word(2, '/etc/passwd'), $this->word(2, 'C:/x'), $this->word(4, '1:/x'), $this->word(3, 'app/../x'), $this->word(3, 'app/./x'),
            $this->word(4, 'lib/x.php'), $this->word(5, 'app/vendor/x'), $this->word(0, 'app/vendor'), $this->word(0, 'README.md'),
            $this->word(0, '.env.example'), $this->word(6, 'app/.ENV'), $this->word(6, 'app/.env.local'), $this->word(7, 'config/Private_Key.php'),
            $this->word(7, 'tests/a.pem'), $this->word(7, "tests/a.pem\n/b"), $this->word(0, "tests/a.pem\n\n/b"), $this->word(0, 'app/private__key'),
            7, 2, 2, 2, 2, 1, 2, 3, 6,
        ];
        $this->assertWords(ProjectPathGuard::class, [$paths], $words);
        $this->assertParity(ProjectPathGuard::class, [$paths]);
        $this->assertRandomParity(ProjectPathGuard::class, 5710);
    }

    public function test_path_guard_status_agrees_with_normalize_checks(): void
    {
        $guard = new ProjectPathGuard;
        $random = new Randomizer(new Mt19937(5715));
        $paths = $this->fixture(ProjectPathGuard::class, $random, true)[0];
        $plan = $this->call(ProjectPathGuard::class, 'Native', [$paths]);
        $messages = [
            1 => 'A non-empty repository-relative path is required.', 2 => 'Absolute paths are not allowed.', 3 => 'Path traversal is not allowed.',
            4 => 'is outside the allowed source roots.', 5 => 'is not available through MCP.', 6 => 'Environment files other than .env.example',
            7 => 'Credential and private-key files',
        ];
        $checks = new ReflectionMethod(ProjectPathGuard::class, 'w57PathStatus');
        foreach ($paths as $i => $path) {
            $status = $plan[$i] & 0xFF;
            if ($status === 1 || $status === 2) {
                continue;
            }
            $normalized = trim(str_replace('\\', '/', $path));
            $normalized = preg_replace('#/+#', '/', $normalized) ?? $normalized;
            self::assertSame($status, $checks->invoke(null, $normalized, explode('/', $normalized)), $path);
            if ($status >= 6) {
                try {
                    $guard->assertNotSensitivePhp($normalized);
                    self::fail('Expected a sensitivity rejection for '.$path);
                } catch (\InvalidArgumentException $e) {
                    self::assertStringContainsString($messages[$status], $e->getMessage(), $path);
                }
            }
        }
    }

    public function test_history_plan_parity(): void
    {
        $this->requireBindings();
        $outputs = ["abc1234\tFix\tthings\r\ndef5678\tAdd\n", '', "\tno commit\x85zz\x0c", "abc1234\tdup", "a\n\rb\x0b\x0bc"];
        $words = [
            4 | 2 << 8 | crc32("abc1234\tFix\tthings\ndef5678\tAdd") << 32, 1 | crc32('') << 32, 1 | 2 | 2 << 8 | crc32("no commit\t\nzz\t") << 32,
            4 | 1 << 8 | crc32("abc1234\tdup") << 32, 1 | 2 | 3 << 8 | crc32("a\t\nb\t\nc\t") << 32,
            8, 1, 3, 2, 7,
        ];
        $this->assertWords(RepositoryContextCollector::class, [$outputs], $words);
        $this->assertParity(RepositoryContextCollector::class, [$outputs]);
        $this->assertRandomParity(RepositoryContextCollector::class, 5720);
    }

    public function test_redaction_plan_parity(): void
    {
        $this->requireBindings();
        $keys = ['api_key', 'name', 'name', 'x', 'pass', 'DB_PASSWORD'];
        $values = ['v', 'Bearer abcdef', 'plain value', 'tiny', 'url https://u:p@h/x', 'x'];
        $words = [
            $this->word(1, '[REDACTED]'), $this->word(2, 'Bearer [REDACTED]'), $this->word(0, 'plain value'), $this->word(0, 'tiny'),
            $this->word(2, 'url https://[REDACTED]@h/x'), $this->word(1, '[REDACTED]'),
            2, 2, 2, 10 + 17 + 11 + 4 + 26 + 10,
        ];
        $this->assertWords(SensitiveValueRedactor::class, [$keys, $values], $words);
        $this->assertParity(SensitiveValueRedactor::class, [$keys, $values]);
        // The words agree with redactValue() on the same key => value map.
        $redactor = new SensitiveValueRedactor;
        [$keys, $values] = $this->fixture(SensitiveValueRedactor::class, new Randomizer(new Mt19937(5725)), true);
        $plan = $this->call(SensitiveValueRedactor::class, 'Native', [$keys, $values]);
        foreach ($keys as $i => $key) {
            $result = SensitiveValueRedactor::withW57RustFfi(false, static fn (): mixed => $redactor->redactValue([$key => $values[$i]]))[$key];
            self::assertIsString($result);
            self::assertSame(crc32($result), $plan[$i] >> 32 & 0xFFFFFFFF, $key);
        }
        $this->assertRandomParity(SensitiveValueRedactor::class, 5730);
    }

    public function test_status_plan_parity(): void
    {
        $this->requireBindings();
        $args = [['operational', 'major_outage', 'bogus', 'maintenance', 'Degraded', 'partial_outage'], [250, -5, 7, null, null, PHP_INT_MAX]];
        $words = [
            1 | 8 | 16 | 1 << 8 | 250 << 16, 4 | 16 | 5 << 8, 16 | 7 << 16, 5 | 3 << 8, 0, 3 | 16 | 4 << 8 | 0xFFFFFFFFFF << 16,
            2, 1, 0, 1, 1, 1, 1, 3, 2, 4, PHP_INT_MAX, PHP_INT_MAX, 4,
        ];
        $this->assertWords(ServiceStatusCollector::class, $args, $words);
        $this->assertParity(ServiceStatusCollector::class, $args);
        // The words agree with evaluateServiceMetricsPhp() for every known status.
        [$statuses, $times] = $this->fixture(ServiceStatusCollector::class, new Randomizer(new Mt19937(5735)), false);
        $plan = $this->call(ServiceStatusCollector::class, 'Native', [$statuses, $times]);
        foreach ($statuses as $i => $status) {
            $metrics = ServiceStatusCollector::evaluateServiceMetricsPhp($status, $times[$i]);
            self::assertSame($metrics['up'], $plan[$i] >> 3 & 1, $status);
            self::assertSame($metrics['severity'], ($plan[$i] >> 8 & 0xFF) - 1, $status);
            self::assertSame($metrics['response_time_seconds'] !== null, ($plan[$i] >> 4 & 1) === 1, $status);
        }
        $this->assertRandomParity(ServiceStatusCollector::class, 5740);
    }

    public function test_count_key_plan_parity(): void
    {
        $this->requireBindings();
        $args = [['processmovies', 'parts_table', 'pc', 'nfo', 'other', 'pc', 'process'], 100, 50, 10];
        $words = [
            $this->word(1, 'movies'), $this->word(2 | 2 << 3, 'parts'), $this->word(3 | 5 << 3, 'pc'), $this->word(4 | 6 << 3, 'nfo'),
            $this->word(0, ''), $this->word(3 | 5 << 3 | 128, 'pc'), $this->word(1, ''),
            1, 2, 1, 2, 1, 1, 0, 0,
        ];
        $this->assertWords(TmuxSnapshotCollector::class, $args, $words);
        $this->assertParity(TmuxSnapshotCollector::class, $args);
        // The classes agree with extractCountSeriesPhp() on a unique-key snapshot.
        $collector = (new ReflectionClass(TmuxSnapshotCollector::class))->newInstanceWithoutConstructor();
        $keys = array_values(array_unique($this->fixture(TmuxSnapshotCollector::class, new Randomizer(new Mt19937(5745)), false)[0]));
        $series = $collector->extractCountSeriesPhp(array_combine($keys, range(1, \count($keys))));
        $plan = $this->call(TmuxSnapshotCollector::class, 'Native', [$keys, 0, 0, 0]);
        $classes = array_count_values(array_map(static fn (int $w): int => $w & 7, \array_slice($plan, 0, \count($keys))));
        self::assertSame(\count($series['backlog']), $classes[1] ?? 0);
        self::assertSame(\count($series['tables']), $classes[2] ?? 0);
        self::assertSame(\count($series['categories']), $classes[3] ?? 0);
        $this->assertRandomParity(TmuxSnapshotCollector::class, 5750);
    }

    /**
     * @param  key-of<self::ENTRIES>  $class
     * @param  list<mixed>  $args
     */
    #[DataProvider('edgeCases')]
    public function test_edge_case_parity(string $class, array $args): void
    {
        $this->requireBindings();
        $this->assertParity($class, $args);
    }

    /** @return iterable<string, array{key-of<self::ENTRIES>, list<mixed>}> */
    public static function edgeCases(): iterable
    {
        $long = str_repeat('k', 70000);
        $binary = "\xff\xfe\x01".str_repeat("\xDB\xB2\x85", 30);
        yield 'paths empty' => [ProjectPathGuard::class, [[]]];
        yield 'paths bounds' => [ProjectPathGuard::class, [[
            '', '/', '//', '\\', 'a', 'app', 'app/', 'app//', '.', '..', 'app/.', 'app/..', './app', 'z:/x', 'Z:\\x', 'zz:/x', ':/x', "\x0Bapp/x\x0B",
            " \t\n\rapp/x\0", "\x0capp/x", 'app/'.$long, $long, $binary, 'app/'.$binary, 'tests/.env', 'tests/.env.', 'tests/.ENV.EXAMPLE',
            'tests/.env.example.bak', 'tests/x.env', 'app/public/build', 'public/build/x', 'app/public/buildx/y', 'app/storage-runtime',
            'app/storage-runtime/x', 'app/.git/x', 'app/node_modules', 'app/node_modules/', 'vendor/x', 'app/CREDENTIALS.md', 'app/id_rsa.pub',
            'app/ID_RSA', 'app/id_ed25519', 'app/x.p12', "app/x.PFX\n", "app/x.pfx\n\n", 'app/x.pem/y', 'app/privatekey', 'app/private-key',
            'app/private_KEY', 'app/private--key', 'app/privat', 'composer.json', 'composer.json/x', 'README.md ', ' README.md', 'readme.md',
        ]]];
        yield 'history empty' => [RepositoryContextCollector::class, [[]]];
        yield 'history bounds' => [RepositoryContextCollector::class, [[
            '', "\n", "\r\n", "\r\r\n\n", "\t", "\t\t", "a\t", "\ta", "a\tb\tc", "A1B2C3D\tupper", "abc\tshort", str_repeat('a', 40)."\tmax",
            str_repeat('a', 41)."\tover", "abcd\x85efgh", "abcd\x0cefgh\x0b", "\x85", "\xc2\x85", $long, "abcd\t".$long, $binary, "a\nb\nc\n\n\nd",
            " \x0B abc1234\tx \x0B ", "abc1234\tx\x0c", "abc1234\tsame\nabc1234\tsame",
        ]]];
        yield 'redaction empty' => [SensitiveValueRedactor::class, [[], []]];
        yield 'redaction bounds' => [SensitiveValueRedactor::class, [
            ['', 'a', 'abcd', 'token', 'TOKEN', 'xtokenx', 'api-key', 'api_key', 'apikey', 'api__key', 'PASSWD', 'secre', 'authorizatio', 'credential',
                'x', 'x', 'x', 'x', 'x', 'x', 'x', 'x', 'x', 'x', 'x', 'x', 'x', 'x'],
            ['secret', 'secret', 'secret', 'x', 'x', 'x', 'x', 'x', 'x', 'password=abc', 'x', 'token=abcdef', 'password: "abc"', 'x',
                '', 'short', 'Bearer', 'Bearer abc', 'bearer abc==', 'xBearer abc', "Bearer\t\nabc", 'https://user:pass@example.com/x',
                'redis://:pass@h', "-----BEGIN RSA PRIVATE KEY-----\nabc\n-----END RSA PRIVATE KEY-----", 'DB_PASSWORD=x y=z API_TOKEN=abc',
                "password => 'x', token = \"y\"", $long, $binary.' password='.$binary],
        ]];
        yield 'status empty' => [ServiceStatusCollector::class, [[], []]];
        yield 'status bounds' => [ServiceStatusCollector::class, [
            ['', 'operational', 'OPERATIONAL', ' degraded', 'degraded ', 'partial_outage', 'major_outage', 'maintenance', 'unknown', $binary, $long,
                'operational', 'degraded', 'maintenance', 'major_outage'],
            [null, 0, -1, PHP_INT_MIN, PHP_INT_MAX, 0xFFFFFFFFFF, 0xFFFFFFFFFF + 1, 1, null, 5, 5, PHP_INT_MAX, PHP_INT_MAX, null, -7],
        ]];
        yield 'status all null' => [ServiceStatusCollector::class, [['operational', 'degraded'], [null, null]]];
        yield 'count keys empty' => [TmuxSnapshotCollector::class, [[], 0, 0, 0]];
        yield 'count keys bounds' => [TmuxSnapshotCollector::class, [
            ['', 'process', 'Process', 'processX', 'processtv', 'processtv', '_table', 'parts', 'parts_table ', 'PC', 'pc', 'misc', 'all_groups',
                'all_group', $long, 'process'.$long, $binary, 'tv', 'tv'],
            1_700_000_000, 1_700_000_100, 99,
        ]];
        foreach ([[PHP_INT_MIN, PHP_INT_MAX, 0], [PHP_INT_MAX, PHP_INT_MIN, 0], [-1, PHP_INT_MAX, PHP_INT_MAX], [5, 5, -5], [4, 5, 0], [0, 10, 10], [0, 10, 9],
            [-10, 10, PHP_INT_MIN]] as [$collectedAt, $now, $stale]) {
            yield "count keys age {$collectedAt} {$now} {$stale}" => [TmuxSnapshotCollector::class, [['tv', 'processx'], $collectedAt, $now, $stale]];
        }
    }

    /**
     * Paired randomized benchmark: ROUNDS interleaved rounds visit every class and workload in
     * turn; each round times PAIRS pairs (after WARMUPS) of the PHP and Rust batch entry point on
     * one randomly chosen fixture, alternating which runs first, each side keeping the fastest of
     * REPEATS back-to-back calls. Common batches hold 320 typical records, Uncommon batches
     * 320-640 malformed or adversarial ones. Mean and nearest-rank p99 speedups pool every
     * round's samples; per-round medians are reported too. Every pair must agree; raw pairs are
     * written outside the checkout. A class must default to Rust exactly when all four metrics
     * reach 1.5x. Gated behind NNTMUX_W572_BENCH=1.
     */
    public function test_paired_randomized_common_and_uncommon_benchmarks(): void
    {
        if (getenv('NNTMUX_W572_BENCH') !== '1') {
            self::markTestSkipped('Set NNTMUX_W572_BENCH=1 to run the Wave 57.2 paired benchmark.');
        }
        $this->requireBindings();
        $random = new Randomizer(new Mt19937(57));
        $fixtures = $samples = $records = [];
        foreach (array_keys(self::ENTRIES) as $class) {
            foreach (['Common', 'Uncommon'] as $workload) {
                for ($v = 0; $v < 8; $v++) {
                    $fixtures[$class][$workload][] = $this->fixture($class, $random, $workload === 'Uncommon');
                }
            }
        }
        for ($round = 0; $round < self::ROUNDS; $round++) {
            foreach (array_keys(self::ENTRIES) as $class) {
                foreach (['Common', 'Uncommon'] as $workload) {
                    for ($i = -self::WARMUPS; $i < self::PAIRS; $i++) {
                        $variant = $random->getInt(0, 7);
                        $order = ($i & 1) === 0 ? [false, true] : [true, false];
                        $results = $elapsed = [];
                        foreach ($order as $native) {
                            $args = $fixtures[$class][$workload][$variant];
                            [$results[(int) $native], $elapsed[(int) $native]] = $class::withW57RustFfi($native, fn (): array => $this->timed($class, $args));
                        }
                        self::assertSame($results[0], $results[1], $class.' '.$workload);
                        if ($i >= 0) {
                            $samples[$class][$workload][$round][] = [$elapsed[0], $elapsed[1]];
                            $records[] = [$class, $workload, $round, $variant, $order[0], $elapsed[0], $elapsed[1]];
                        }
                    }
                }
            }
        }
        $summary = [];
        foreach ($samples as $class => $workloads) {
            $metrics = [];
            foreach ($workloads as $workload => $rounds) {
                $php = $rust = $roundMeans = $roundP99s = [];
                foreach ($rounds as $pairs) {
                    $roundPhp = array_column($pairs, 0);
                    $roundRust = array_column($pairs, 1);
                    $roundMeans[] = array_sum($roundPhp) / array_sum($roundRust);
                    $roundP99s[] = $this->p99($roundPhp) / $this->p99($roundRust);
                    array_push($php, ...$roundPhp);
                    array_push($rust, ...$roundRust);
                }
                $summary[$class][$workload] = [
                    'mean_speedup' => round(array_sum($php) / array_sum($rust), 3), 'p99_speedup' => round($this->p99($php) / $this->p99($rust), 3),
                    'round_median_mean_speedup' => round($this->median($roundMeans), 3), 'round_median_p99_speedup' => round($this->median($roundP99s), 3),
                    'php_mean_ns' => (int) (array_sum($php) / \count($php)), 'rust_mean_ns' => (int) (array_sum($rust) / \count($rust)),
                    'php_p99_ns' => $this->p99($php), 'rust_p99_ns' => $this->p99($rust), 'samples' => \count($php),
                ];
                $metrics[] = $summary[$class][$workload]['mean_speedup'];
                $metrics[] = $summary[$class][$workload]['p99_speedup'];
            }
            $summary[$class]['qualifies'] = min($metrics) >= 1.5;
        }
        $artifact = sys_get_temp_dir().'/w572-benchmark-'.bin2hex(random_bytes(6)).'.json';
        file_put_contents($artifact, json_encode(['php' => PHP_VERSION, 'os' => php_uname(), 'rounds' => self::ROUNDS, 'pairs' => self::PAIRS, 'warmups' => self::WARMUPS,
            'repeats' => self::REPEATS, 'seed' => 57, 'summary' => $summary, 'raw_pairs' => $records], JSON_THROW_ON_ERROR));
        fwrite(STDERR, "\nW57.2 paired benchmark artifact: {$artifact}\n".json_encode($summary, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n");
        foreach (array_keys(self::ENTRIES) as $class) {
            self::assertSame($summary[$class]['qualifies'], $class::usesW57RustFfi(), $class.' default must follow the measured 1.5x gate');
        }
    }

    /**
     * An explicit setlocale() makes PHP build PCRE tables from LC_CTYPE, so the regex-backed
     * path guard and redaction plans hand those batches to PHP. Declared last: once a script
     * sets a locale, later regexes keep the rebuilt tables.
     */
    public function test_native_helpers_fall_back_to_php_under_locale_tables(): void
    {
        $this->requireBindings();
        $locale = setlocale(LC_CTYPE, '0');
        try {
            foreach (['C.UTF-8', 'en_US.ISO8859-1', 'tr_TR.ISO8859-9'] as $candidate) {
                if (setlocale(LC_CTYPE, $candidate) === false) {
                    continue;
                }
                $this->assertParity(ProjectPathGuard::class, [["app/CR\xC9DENTIAL", 'app/CREDENTIAL', "app/x.P\xC9M", 'app/ID_RSA', "app/.\xC9NV"]]);
                $this->assertParity(SensitiveValueRedactor::class, [["T\xD6KEN", 'TOKEN', 'name'], ["PASSWORD=\xE9x", "BEARER \xC9abc", "x\xA0token=y"]]);
                $this->assertParity(RepositoryContextCollector::class, [["abc1234\t\xC9t\xE9\x85def5678\tx"]]);
                $this->assertParity(ServiceStatusCollector::class, [["OP\xC9RATIONAL", 'degraded'], [1, null]]);
                $this->assertParity(TmuxSnapshotCollector::class, [["process\xE9", 'TV'], 1, 2, 3]);
            }
        } finally {
            setlocale(LC_CTYPE, (string) $locale);
        }
    }

    // ---- helpers -----------------------------------------------------------------------------

    private function word(int $flags, string $text): int
    {
        return $flags | min(\strlen($text), 0xFFFFFF) << 8 | crc32($text) << 32;
    }

    /**
     * The fastest of REPEATS back-to-back calls of the class's batch entry point.
     *
     * @param  key-of<self::ENTRIES>  $class
     * @param  list<mixed>  $args
     * @return array{list<int>, int}
     */
    private function timed(string $class, array $args): array
    {
        $best = PHP_INT_MAX;
        $result = [];
        for ($r = 0; $r < self::REPEATS; $r++) {
            $start = hrtime(true);
            $result = $this->call($class, '', $args);
            $best = min($best, hrtime(true) - $start);
        }

        return [$result, $best];
    }

    /** @param  list<int>  $values */
    private function p99(array $values): int
    {
        sort($values);

        return $values[max(0, (int) ceil(\count($values) * 0.99) - 1)];
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
        $value = (new ReflectionClass($class))->getConstant('W57_RUST_FFI_DEFAULT');
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
        $php = $class::withW57RustFfi(false, fn (): array => $this->call($class, '', $args));
        $rust = $class::withW57RustFfi(true, fn (): array => $this->call($class, '', $args));
        self::assertSame($php, $rust, $class);
        self::assertSame($php, $this->call($class, 'Php', $args), $class);
        self::assertSame($php, $this->call($class, 'Native', $args), $class);
        self::assertCount(\count($args[0]) + self::TAIL[$class], $php, $class);
        self::assertSame($this->digest(\array_slice($php, 0, -1)), $php[\count($php) - 1], $class);
    }

    /**
     * Documented words (without the digest) from both implementations.
     *
     * @param  key-of<self::ENTRIES>  $class
     * @param  list<mixed>  $args
     * @param  list<int>  $words
     */
    private function assertWords(string $class, array $args, array $words): void
    {
        foreach (['Php', 'Native'] as $suffix) {
            self::assertSame($words, \array_slice($this->call($class, $suffix, $args), 0, -1), $class.'::'.$suffix);
        }
    }

    /**
     * PARITY_BATCHES Common and Uncommon batches (at least 300 items each) must match, and the
     * Rust export must actually produce them (no silent PHP fallback).
     *
     * @param  key-of<self::ENTRIES>  $class
     */
    private function assertRandomParity(string $class, int $seed): void
    {
        $items = 0;
        foreach ([false, true] as $uncommon) {
            for ($i = 0; $i < self::PARITY_BATCHES; $i++) {
                $args = $this->fixture($class, new Randomizer(new Mt19937($seed + 2 * $i + (int) $uncommon)), $uncommon);
                $this->assertParity($class, $args);
                self::assertSame($this->call($class, 'Php', $args), $this->exported($class, $args), $class);
                $items += \count($args[0]);
            }
        }
        self::assertGreaterThanOrEqual(300 * 2 * self::PARITY_BATCHES, $items, $class);
    }

    /**
     * Calls the export directly with the entry point's framing.
     *
     * @param  key-of<self::ENTRIES>  $class
     * @param  list<mixed>  $args
     * @return list<int>
     */
    private function exported(string $class, array $args): array
    {
        $ffi = $this->ffi($class);
        $count = \count($args[0]);
        $cap = $count + 16;
        $out = $ffi->new('int64_t['.$cap.']');
        $call = match ($class) {
            ProjectPathGuard::class, RepositoryContextCollector::class => [$b = implode("\0", $args[0]), \strlen($b), $count],
            SensitiveValueRedactor::class => [$k = implode("\0", $args[0]), \strlen($k), $v = implode("\0", $args[1]), \strlen($v), $count],
            ServiceStatusCollector::class => [$s = implode("\0", $args[0]), \strlen($s), $t = pack('P*', ...$args[1]), \strlen($t),
                $n = pack('P*', ...array_keys($args[1], null, true)), \strlen($n), $count],
            TmuxSnapshotCollector::class => [$k = implode("\0", $args[0]), \strlen($k), $count, $args[1], $args[2], $args[3]],
        };
        $written = $this->invoke($ffi, self::SYMBOLS[$class], [...$call, $out, $cap]);
        self::assertIsInt($written);
        self::assertGreaterThan(0, $written, $class);
        /** @var array<int, int> $values */
        $values = unpack('P*', FFI::string($out, $written * 8));

        return array_values($values);
    }

    /** @param  key-of<self::ENTRIES>  $class */
    private function ffi(string $class): FFI
    {
        $ffi = (new ReflectionMethod($class, 'w57RustFfiState'))->invoke(null, 'ffi');
        self::assertInstanceOf(FFI::class, $ffi, $class.' must load the nfo_w57 binding');

        return $ffi;
    }

    /** Each Wave 57.2 export must resolve and accept a one-item batch, so parity runs Rust. */
    private function requireBindings(): void
    {
        self::assertTrue(\extension_loaded('FFI'), 'FFI extension required');
        self::assertTrue(NzbParserService::pcreUsesDefaultTables(), 'PCRE must use its default tables so parity exercises Rust');
        $calls = [
            ProjectPathGuard::class => [['app', 3, 1], 11],
            RepositoryContextCollector::class => [["a\tb", 3, 1], 7],
            SensitiveValueRedactor::class => [['k', 1, 'v', 1, 1], 6],
            ServiceStatusCollector::class => [['degraded', 8, pack('P', 5), 8, null, 0, 1], 15],
            TmuxSnapshotCollector::class => [['nfo', 3, 1, 1, 2, 3], 10],
        ];
        foreach ($calls as $class => [$args, $words]) {
            $ffi = $this->ffi($class);
            $out = $ffi->new('int64_t[32]');
            self::assertSame($words, $this->invoke($ffi, self::SYMBOLS[$class], [...$args, $out, 32]), $class);
        }
    }

    /**
     * Calls an export declared by a class's W57_FFI_CDEF.
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
            ProjectPathGuard::class => [$this->pathBatch($random, $uncommon)],
            RepositoryContextCollector::class => [$this->historyBatch($random, $uncommon)],
            SensitiveValueRedactor::class => $this->redactionBatch($random, $uncommon),
            ServiceStatusCollector::class => $this->statusBatch($random, $uncommon),
            TmuxSnapshotCollector::class => $this->countKeyBatch($random, $uncommon),
        };
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

    /** Printable noise, or binary noise without NUL bytes. */
    private function noise(Randomizer $random, int $max, bool $binary = false): string
    {
        $length = $random->getInt(0, $max);
        if ($length === 0) {
            return '';
        }

        return $binary ? str_replace("\0", "\1", $random->getBytes($length))
            : $random->getBytesFromString('abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789._-/ ', $length);
    }

    private function batchSize(Randomizer $random, bool $uncommon): int
    {
        return $uncommon ? $random->getInt(320, 640) : 320;
    }

    /** @return list<string> */
    private function pathBatch(Randomizer $random, bool $uncommon): array
    {
        $paths = [];
        for ($i = $this->batchSize($random, $uncommon); $i > 0; $i--) {
            $path = $this->pick($random, self::SOURCE_PATHS);
            if ($random->getInt(0, 3) === 0) {
                $path = $this->pick($random, ['app', 'tests', 'config', 'resources']).'/'.$this->noise($random, 30).'.php';
            }
            if ($uncommon) {
                $path = match ($random->getInt(0, 17)) {
                    0 => '',
                    1 => '/'.$path,
                    2 => str_replace('/', '\\', $path),
                    3 => str_replace('/', '//', $path),
                    4 => $this->pick($random, ['../', './', 'app/../', 'app/./']).$path,
                    5 => $this->pick($random, ['app/', 'config/', 'tests/']).$this->pick($random, ['.env', '.ENV.local', '.env.example', '.env.testing']),
                    6 => $this->pick($random, ['app/', 'tests/']).$this->pick($random, ['credentials.json', 'Private_Key.php', 'id_rsa', 'ID_ED25519',
                        'server.PEM', "cert.pem\n", 'store.p12', 'x.pfx.bak', 'privatekey.txt']).($random->getInt(0, 1) === 0 ? '' : '/x'),
                    7 => $this->pick($random, ['app/vendor/', 'app/storage/', 'tests/node_modules/', 'app/public/build/', 'vendor/', 'storage/']).$path,
                    8 => $this->noise($random, 120, true),
                    9 => $this->pick($random, [' ', "\t", "\n", "\x0B", "\r\n"]).$path.$this->pick($random, [' ', "\n", "\x0c", '']),
                    10 => $this->pick($random, ['C:', 'z:', '1:', 'ab:']).'/'.$path,
                    11 => $this->pick($random, ['lib/', 'public/', 'docker/', '.git/', '.ai/']).$path,
                    12 => $path.'/'.$this->noise($random, 40),
                    default => $path,
                };
            }
            $paths[] = $path;
        }

        return $paths;
    }

    /** @return list<string> */
    private function historyBatch(Randomizer $random, bool $uncommon): array
    {
        $outputs = [];
        for ($i = $this->batchSize($random, $uncommon); $i > 0; $i--) {
            $lines = [];
            for ($l = $random->getInt(1, 5); $l > 0; $l--) {
                $lines[] = bin2hex($random->getBytes(5)).($random->getInt(0, 3) === 0 ? 'ab' : '')."\t".$this->pick($random, self::SUBJECTS);
            }
            $output = implode("\n", $lines)."\n";
            if ($uncommon) {
                $output = match ($random->getInt(0, 11)) {
                    0 => '',
                    1 => implode("\r\n", $lines),
                    2 => implode($this->pick($random, ["\x85", "\x0B", "\x0c", "\r", "\n\r", "\n\n"]), $lines),
                    3 => "\t".implode("\n\t", $lines),
                    4 => str_replace("\t", ' ', $output),
                    5 => strtoupper($output),
                    6 => $this->noise($random, 300, true),
                    7 => $output."\t".$this->noise($random, 40)."\n".$this->noise($random, 20),
                    8 => $this->pick($random, [' ', "\x0B", "\t"]).$output.$this->pick($random, ["\x0c", "\x85", ' ']),
                    default => $output,
                };
            }
            $outputs[] = $output;
        }

        return $outputs;
    }

    /** @return array{list<string>, list<string>} */
    private function redactionBatch(Randomizer $random, bool $uncommon): array
    {
        $keys = $values = [];
        for ($i = $this->batchSize($random, $uncommon); $i > 0; $i--) {
            $key = $this->pick($random, self::KEYS);
            $value = match ($random->getInt(0, 7)) {
                0 => 'password='.$this->noise($random, 16),
                1 => 'Authorization: Bearer '.bin2hex($random->getBytes(16)),
                2 => 'mysql://nntmux:'.bin2hex($random->getBytes(6)).'@127.0.0.1:3306/nntmux',
                3 => 'NNTP_PASSWORD='.$this->noise($random, 12).' NNTP_USERNAME=user',
                4 => '{"api_key": "'.bin2hex($random->getBytes(8)).'", "name": "x"}',
                default => $this->pick($random, ['Fetched 20 releases in 1.3s', 'Queue default drained', 'host=localhost port=3306',
                    'SQLSTATE[HY000] [2002] Connection refused', 'Processing group alt.binaries.teevee']),
            };
            if ($uncommon) {
                $key = match ($random->getInt(0, 5)) {
                    0 => $this->noise($random, 12),
                    1 => strtoupper($key),
                    2 => $this->noise($random, 6).$key.$this->noise($random, 6),
                    default => $key,
                };
                $value = match ($random->getInt(0, 9)) {
                    0 => '',
                    1 => $this->noise($random, 5),
                    2 => "-----BEGIN PRIVATE KEY-----\n".base64_encode($random->getBytes(48))."\n-----END PRIVATE KEY-----".$this->noise($random, 10),
                    3 => $this->noise($random, 300, true),
                    4 => $value.' '.$this->noise($random, 200).' secret: '.$this->noise($random, 10),
                    5 => strtolower($value)."\ttoken => '".$this->noise($random, 10)."'",
                    6 => 'bearer   '.$this->noise($random, 30).'== https://a:b@c.d user:pw@x',
                    default => $value,
                };
            }
            $keys[] = $key;
            $values[] = $value;
        }

        return [$keys, $values];
    }

    /** @return array{list<string>, list<int|null>} */
    private function statusBatch(Randomizer $random, bool $uncommon): array
    {
        $statuses = $times = [];
        for ($i = $this->batchSize($random, $uncommon); $i > 0; $i--) {
            $status = $random->getInt(0, 4) === 0 ? $this->pick($random, self::STATUSES) : 'operational';
            $time = $random->getInt(0, 5) === 0 ? null : $random->getInt(1, 3000);
            if ($uncommon) {
                $status = match ($random->getInt(0, 7)) {
                    0 => '',
                    1 => strtoupper($status),
                    2 => $this->noise($random, 20),
                    3 => $status.' ',
                    default => $status,
                };
                $time = match ($random->getInt(0, 7)) {
                    0 => null,
                    1 => $this->pick($random, [PHP_INT_MIN, -1, 0, PHP_INT_MAX, 0xFFFFFFFFFF, 0xFFFFFFFFFF + 1]),
                    2 => $random->getInt(PHP_INT_MIN, PHP_INT_MAX),
                    default => $time,
                };
            }
            $statuses[] = $status;
            $times[] = $time;
        }

        return [$statuses, $times];
    }

    /** @return array{list<string>, int, int, int} */
    private function countKeyBatch(Randomizer $random, bool $uncommon): array
    {
        $keys = [];
        for ($i = $this->batchSize($random, $uncommon); $i > 0; $i--) {
            $key = $this->pick($random, self::COUNT_KEYS);
            if ($uncommon) {
                $key = match ($random->getInt(0, 7)) {
                    0 => '',
                    1 => strtoupper($key),
                    2 => 'process'.$this->noise($random, 30),
                    3 => $this->noise($random, 40, true),
                    4 => $key.$this->pick($random, ['_table', ' ', 's']),
                    default => $key,
                };
            }
            $keys[] = $key;
        }
        $now = 1_790_000_000;

        return $uncommon
            ? [$keys, $this->pick($random, [PHP_INT_MIN, -1, 0, $now, $now + 5, PHP_INT_MAX]), $this->pick($random, [PHP_INT_MIN, 0, $now, PHP_INT_MAX]),
                $this->pick($random, [PHP_INT_MIN, -1, 0, 60, PHP_INT_MAX])]
            : [$keys, $now - $random->getInt(0, 600), $now, 300];
    }
}
