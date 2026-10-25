<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Console\Commands\CheckServiceUptime;
use App\Enums\ServiceStatusEnum;
use App\Models\ServiceStatus;
use App\Services\NntmuxUpdateLock;
use App\Services\Releases\Verification\FileVerificationLockStore;
use App\Services\Releases\Verification\PublicationWorkerGate;
use App\Services\Releases\Verification\VerificationLock;
use App\Services\SiteStatusService;
use FFI;
use Illuminate\Console\OutputStyle;
use Illuminate\Database\Eloquent\Collection;
use PHPUnit\Framework\Attributes\DataProvider;
use Random\Engine\Mt19937;
use Random\Randomizer;
use ReflectionClass;
use ReflectionProperty;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Process\Process;
use Tests\TestCase;
use TypeError;

final class RustVerificationLocksUptimeParityTest extends TestCase
{
    private const CLASSES = [FileVerificationLockStore::class, VerificationLock::class, PublicationWorkerGate::class, NntmuxUpdateLock::class, CheckServiceUptime::class];

    protected function tearDown(): void
    {
        foreach (self::CLASSES as $class) {
            if (method_exists($class, 'usesRustFfi')) {
                $class::setUseRustFfi(null);
                (new ReflectionProperty($class, 'ffi'))->setValue(null, null);
            }
        }
        parent::tearDown();
    }

    private function library(): FFI
    {
        $path = base_path('rust/pipeline-ffi/target/release/libnntmux_pipeline.'.(PHP_OS_FAMILY === 'Darwin' ? 'dylib' : 'so'));
        self::assertFileExists($path, 'Build and copy the isolated release library first.');
        $cdef = (new ReflectionClass(FileVerificationLockStore::class))->getConstant('FFI_CDEF');
        self::assertIsString($cdef);

        return FFI::cdef($cdef, $path);
    }

    /** @return list<array{class-string, string, list<mixed>}> */
    private function cases(): array
    {
        $cases = [];
        foreach (['', 'worker', '../worker', " \tname\0\xff", str_repeat('é', 2048)] as $name) {
            $cases[] = [FileVerificationLockStore::class, 'buildLockFilePath', ['/tmp/locks/', $name]];
            $cases[] = [VerificationLock::class, 'normalizeLockSpec', [$name, -10]];
            $cases[] = [NntmuxUpdateLock::class, 'formatWaitingMessage', [$name, 1.25]];
            foreach ([null, '', "now\0\xff"] as $checked) {
                foreach ([99.99, -0.0, -0.004, 1.005, -1.005, 1234567.891, INF, -INF, NAN, PHP_FLOAT_MAX] as $uptime) {
                    $cases[] = [CheckServiceUptime::class, 'formatServiceUptimeRow', [$name, "ok\0\xff", $uptime, $checked]];
                }
            }
        }
        foreach ([[0, 0], [100, -1], [-100, 0], [100, 10], [PHP_INT_MAX, 0], [PHP_INT_MIN, 1]] as $args) {
            $cases[] = [FileVerificationLockStore::class, 'computePollDeadlineNs', $args];
        }
        foreach ([PHP_INT_MIN, -1, 0, 1, 10, PHP_INT_MAX - 1] as $depth) {
            foreach ([false, true] as $acquired) {
                $cases[] = [PublicationWorkerGate::class, 'classifyJoinTransition', [$depth, $acquired]];
            }
        }
        foreach ([-INF, INF, NAN, PHP_FLOAT_MAX, -0.0, -0.04, 0.05, -1.25, 1e20] as $elapsed) {
            $cases[] = [NntmuxUpdateLock::class, 'formatWaitingMessage', ['nntmux:git', $elapsed]];
        }
        $random = new Randomizer(new Mt19937(336));
        for ($i = 1; $i <= 100; $i++) {
            $bytes = $random->getBytes($i);
            $cases[] = [FileVerificationLockStore::class, 'buildLockFilePath', [$bytes, $bytes]];
            $cases[] = [VerificationLock::class, 'normalizeLockSpec', [$bytes, $random->getInt(-100, 100)]];
            $cases[] = [NntmuxUpdateLock::class, 'formatWaitingMessage', [$bytes, $random->getInt(-100000, 100000) / 113]];
            $cases[] = [CheckServiceUptime::class, 'formatServiceUptimeRow', [$bytes, $bytes, $random->getInt(-100000, 100000) / 113, $bytes]];
        }

        return $cases;
    }

    public function test_contracts_preserve_bytes_rounding_and_null_checked_values(): void
    {
        self::assertSame('/locks/'.hash('sha256', 'worker').'.lock', FileVerificationLockStore::buildLockFilePath('/locks', 'worker'));
        self::assertSame(2_000_000_100, FileVerificationLockStore::computePollDeadlineNs(100, 2));
        self::assertSame(['name' => " a\0", 'timeout_seconds' => 0], VerificationLock::normalizeLockSpec(" a\0", -1));
        self::assertSame(['joined' => false, 'join_depth' => 0, 'acquire' => true], PublicationWorkerGate::classifyJoinTransition(0, false));
        self::assertSame(['joined' => true, 'join_depth' => 3, 'acquire' => false], PublicationWorkerGate::classifyJoinTransition(2, false));
        self::assertSame('Waiting for nntmux:git (1.2s elapsed)...', NntmuxUpdateLock::formatWaitingMessage('nntmux:git', 1.24));
        self::assertSame(['db', 'up', '1,234.57%', 'never'], CheckServiceUptime::formatServiceUptimeRow('db', 'up', 1234.567, null));
        self::assertSame(['db', 'up', '0.00%', ''], CheckServiceUptime::formatServiceUptimeRow('db', 'up', -0.004, ''));
    }

    public function test_native_php_and_toggle_parity(): void
    {
        $this->library();
        foreach ($this->cases() as [$class, $method, $args]) {
            $expected = $class::{$method.'Php'}(...$args);
            self::assertSame($expected, $class::{$method.'Native'}(...$args), $class.'::'.$method);
            foreach ([false, true, null] as $toggle) {
                $class::setUseRustFfi($toggle);
                self::assertSame($expected, $class::$method(...$args));
            }
        }
    }

    public function test_missing_and_incompatible_libraries_fall_back(): void
    {
        foreach ([false, FFI::cdef('')] as $unavailable) {
            foreach (self::CLASSES as $class) {
                (new ReflectionProperty($class, 'ffi'))->setValue(null, $unavailable);
                $class::setUseRustFfi(true);
                self::assertTrue($class::usesRustFfi());
            }
            foreach ($this->cases() as [$class, $method, $args]) {
                self::assertSame($class::{$method.'Php'}(...$args), $class::$method(...$args));
            }
        }
        foreach (self::CLASSES as $class) {
            $class::setUseRustFfi(null);
            self::assertFalse($class::usesRustFfi());
        }
    }

    /** @return list<array{string}> */
    public static function overflowModes(): array
    {
        return [['Php'], ['Native'], ['']];
    }

    #[DataProvider('overflowModes')]
    public function test_deadline_overflow_keeps_php_type_error_in_both_modes(string $suffix): void
    {
        FileVerificationLockStore::setUseRustFfi(true);
        $this->expectException(TypeError::class);
        FileVerificationLockStore::{'computePollDeadlineNs'.$suffix}(PHP_INT_MAX, 1);
    }

    public function test_raw_export_checks_frames_flags_and_capacity_without_writes(): void
    {
        $ffi = $this->library();
        $out = $ffi->new('uint8_t[256]');
        FFI::memset($out, 90, 256);
        $frame = pack('N', 6).'worker';
        // @phpstan-ignore method.notFound
        $call = fn ($op, $input, $length, $first, $second, $value, $flag, $buffer, $capacity) => $ffi->nntmux_w336_locks_uptime($op, $input, $length, $first, $second, $value, $flag, $buffer, $capacity);
        foreach (['', 'bad', pack('N', 99).'a', $frame.'x'] as $invalid) {
            self::assertSame(-1, $call(3, $invalid, strlen($invalid), 0, 0, 0.0, 0, $out, 256));
        }
        self::assertSame(-1, $call(3, null, 0, 0, 0, 0.0, 0, $out, 256));
        self::assertSame(-1, $call(3, $frame, strlen($frame), 0, 0, 0.0, 0, null, 256));
        self::assertSame(-1, $call(3, $frame, PHP_INT_MAX, 0, 0, 0.0, 0, $out, 256));
        self::assertSame(-1, $call(0, '', 0, 0, 0, 0.0, 0, $out, 256));
        self::assertSame(-1, $call(4, '', 0, 0, 0, 0.0, 2, $out, 256));
        self::assertSame(-16, $call(3, $frame, strlen($frame), 10, 0, 0.0, 0, $out, 15));
        self::assertSame(str_repeat('Z', 256), FFI::string($out, 256));
        self::assertSame(16, $call(3, $frame, strlen($frame), 10, 0, 0.0, 0, $out, 16));
        self::assertSame(pack('N', 6).'worker'.pack('N', 2).'10', FFI::string($out, 16));
        foreach ([1, 2, 3, 4, 5, 6] as $op) {
            $input = match ($op) {
                1 => pack('N', 4).'/tmp'.$frame,
                3, 5 => $frame,
                6 => $frame.pack('N', 2).'up'.pack('N', 0),
                default => '',
            };
            self::assertGreaterThan(0, $call($op, $input, strlen($input), 1, 2, 99.99, 0, $out, 256), 'Every normal operation must execute in Rust.');
        }
    }

    public function test_file_lock_callers_keep_literal_paths_and_exclusion(): void
    {
        $root = sys_get_temp_dir().'/w336-locks-'.bin2hex(random_bytes(8));
        config(['release_verification.storage_path' => $root]);
        try {
            foreach ([false, true] as $toggle) {
                FileVerificationLockStore::setUseRustFfi($toggle);
                $store = new FileVerificationLockStore;
                $lock = $store->exclusive('name');
                self::assertNotNull($lock);
                self::assertSame([$root.'/locks/name.lock'], $lock->keys);
                self::assertNull($store->exclusive('name'));
                $store->release($lock);
                $shared = $store->shared('worker');
                self::assertNotNull($shared);
                self::assertSame([$root.'/worker.lock'], $shared->keys);
                self::assertNull($store->drainGroup('worker', 0));
                $store->release($shared);
                $exclusive = $store->exclusiveGroup('worker');
                self::assertNotNull($exclusive);
                $store->release($exclusive);
            }
        } finally {
            foreach ([$root.'/locks/name.lock', $root.'/worker.lock'] as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
            if (is_dir($root.'/locks')) {
                rmdir($root.'/locks');
            }
            if (is_dir($root)) {
                rmdir($root);
            }
        }
    }

    public function test_publication_gate_keeps_retained_lock_and_sticky_refusal(): void
    {
        $root = sys_get_temp_dir().'/w336-gate-'.bin2hex(random_bytes(8));
        config(['release_verification.storage_path' => $root, 'release_verification.locks' => 'file']);
        try {
            foreach ([false, true] as $toggle) {
                PublicationWorkerGate::setUseRustFfi($toggle);
                $store = new FileVerificationLockStore;
                $gate = new PublicationWorkerGate(retain: true);
                try {
                    self::assertTrue($gate->enter());
                    self::assertTrue($gate->enter());
                    $gate->leave();
                    self::assertNull($store->exclusiveGroup('worker'));
                } finally {
                    $gate->close();
                }
                $barrier = (new FileVerificationLockStore)->exclusiveGroup('worker');
                self::assertNotNull($barrier);
                $refused = new PublicationWorkerGate(retain: true);
                try {
                    self::assertFalse($refused->enter());
                } finally {
                    $store->release($barrier);
                }
                self::assertFalse($refused->enter());
                $single = new PublicationWorkerGate;
                try {
                    self::assertTrue($single->enter());
                    $single->leave();
                    $barrier = (new FileVerificationLockStore)->exclusiveGroup('worker');
                    self::assertNotNull($barrier);
                    $store->release($barrier);
                } finally {
                    $single->close();
                }
            }
        } finally {
            if (is_file($root.'/worker.lock')) {
                unlink($root.'/worker.lock');
            }
            if (is_dir($root)) {
                rmdir($root);
            }
        }
    }

    public function test_uptime_command_preserves_table_cells_and_refresh_window(): void
    {
        foreach ([false, true] as $toggle) {
            CheckServiceUptime::setUseRustFfi($toggle);
            $status = $this->mock(SiteStatusService::class);
            $status->shouldReceive('refreshAllServiceUptime')->once()->with(7);
            $status->shouldReceive('getEnabledServices')->once()->andReturn(new Collection([
                new ServiceStatus(['name' => 'Database', 'status' => ServiceStatusEnum::Operational, 'uptime_percentage' => '99.99', 'last_checked_at' => null]),
            ]));
            $command = new CheckServiceUptime;
            $command->setLaravel($this->app);
            $input = new ArrayInput(['--days' => 7], $command->getDefinition());
            $output = new BufferedOutput;
            $command->setInput($input);
            $command->setOutput(new OutputStyle($input, $output));
            self::assertSame(0, $command->run($input, $output));
            $text = $output->fetch();
            foreach (['7-day window', 'Database', 'Operational', '99.99%', 'never'] as $cell) {
                self::assertStringContainsString($cell, $text);
            }
        }
    }

    /** Raw paired timings and frozen runtime/source identities are written outside the worktree. */
    public function test_paired_randomized_benchmark(): void
    {
        $this->library();
        $large = str_repeat("é\0\xff ", 1024);
        $workloads = [
            [FileVerificationLockStore::class, 'buildLockFilePath', ['/tmp/locks', 'worker'], [$large, $large]],
            [FileVerificationLockStore::class, 'computePollDeadlineNs', [100, 10], [PHP_INT_MIN, 1]],
            [VerificationLock::class, 'normalizeLockSpec', ['worker', 10], [$large, PHP_INT_MAX]],
            [PublicationWorkerGate::class, 'classifyJoinTransition', [0, true], [PHP_INT_MAX - 1, false]],
            [NntmuxUpdateLock::class, 'formatWaitingMessage', ['nntmux:git', 1.23], [$large, 123456.78]],
            [CheckServiceUptime::class, 'formatServiceUptimeRow', ['db', 'up', 99.99, null], [$large, $large, -1234567.891, $large]],
        ];
        $random = new Randomizer(new Mt19937(3306));
        $results = [];
        foreach ($workloads as [$class, $method, $common, $uncommon]) {
            foreach (['common' => $common, 'uncommon' => $uncommon] as $kind => $args) {
                $expected = $class::{$method.'Php'}(...$args);
                self::assertSame($expected, $class::{$method.'Native'}(...$args));
                foreach ([false, true] as $toggle) {
                    $class::setUseRustFfi($toggle);
                    for ($warmup = 0; $warmup < 200; $warmup++) {
                        $class::$method(...$args);
                    }
                }
                $raw = ['php' => [], 'rust' => []];
                for ($round = 0; $round < 3; $round++) {
                    for ($sample = 0; $sample < 2000; $sample++) {
                        foreach ($random->getInt(0, 1) === 0 ? [false, true] : [true, false] as $toggle) {
                            $class::setUseRustFfi($toggle);
                            $start = hrtime(true);
                            $actual = $class::$method(...$args);
                            $raw[$toggle ? 'rust' : 'php'][] = hrtime(true) - $start;
                            self::assertSame($expected, $actual);
                        }
                    }
                }
                $summary = [];
                foreach ($raw as $mode => $samples) {
                    sort($samples);
                    $summary[$mode] = ['mean_ns' => array_sum($samples) / count($samples), 'p99_ns' => $samples[(int) ceil(count($samples) * 0.99) - 1], 'samples' => count($samples)];
                }
                $mean = $summary['php']['mean_ns'] / max(1, $summary['rust']['mean_ns']);
                $p99 = $summary['php']['p99_ns'] / max(1, $summary['rust']['p99_ns']);
                $class::setUseRustFfi(null);
                if ($mean < 1.5 || $p99 < 1.5) {
                    self::assertFalse($class::usesRustFfi(), $class.' failed the Rust adoption gate');
                }
                $key = $class.'::'.$method.'/'.$kind;
                $results[$key] = ['summary' => $summary, 'mean_speedup' => $mean, 'p99_speedup' => $p99, 'input_sha256' => hash('sha256', serialize($args)), 'raw_ns' => $raw];
                fwrite(STDERR, sprintf("\n%s mean=%.3fx p99=%.3fx", $key, $mean, $p99));
            }
        }
        $sources = [];
        foreach (self::CLASSES as $class) {
            $path = (new ReflectionClass($class))->getFileName();
            self::assertIsString($path);
            $sources[$class] = hash_file('sha256', $path);
        }
        $artifact = tempnam(sys_get_temp_dir(), 'nntmux-w33-6-bench-');
        self::assertNotFalse($artifact);
        $tools = [];
        foreach (['commit' => ['git', 'rev-parse', 'HEAD'], 'rustc' => ['rustc', '-Vv'], 'cargo' => ['cargo', '-V'], 'hardware' => PHP_OS_FAMILY === 'Darwin' ? ['sysctl', '-n', 'machdep.cpu.brand_string', 'hw.memsize', 'hw.ncpu'] : ['uname', '-a']] as $key => $argv) {
            $process = new Process($argv, base_path());
            $process->mustRun();
            $tools[$key] = trim($process->getOutput());
        }
        self::assertNotFalse(file_put_contents($artifact, json_encode([
            'utc' => gmdate(DATE_ATOM), 'php' => PHP_VERSION, 'os' => php_uname(),
            'jit' => ini_get('opcache.jit'), 'profile' => 'cargo release',
            'tools' => $tools, 'php_memory_limit' => ini_get('memory_limit'),
            'source_hashes' => $sources,
            'rust_source_sha256' => hash_file('sha256', base_path('rust/pipeline-ffi/src/additional.rs')),
            'composer_lock_sha256' => hash_file('sha256', base_path('composer.lock')),
            'cargo_lock_sha256' => hash_file('sha256', base_path('rust/pipeline-ffi/Cargo.lock')),
            'library_sha256' => hash_file('sha256', base_path('rust/pipeline-ffi/target/release/libnntmux_pipeline.'.(PHP_OS_FAMILY === 'Darwin' ? 'dylib' : 'so'))),
            'warmup_per_mode' => 200, 'rounds' => 3, 'pairs_per_round' => 2000, 'random_seed' => 3306,
            'results' => $results,
        ], JSON_THROW_ON_ERROR)));
        fwrite(STDERR, "\nRaw benchmark evidence: {$artifact}\n");
    }
}
