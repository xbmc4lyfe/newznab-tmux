<?php

namespace Tests\Unit;

use App\Services\Nzb\DirectorySyncBridge;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class DirectorySyncBridgeTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().'/directory-sync-test-'.bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->directory, 0700));
        DirectorySyncBridge::$events = [];
        DirectorySyncBridge::$trace = true;
        DirectorySyncBridge::$fail = '';
        DirectorySyncBridge::$afterSync = null;
    }

    protected function tearDown(): void
    {
        DirectorySyncBridge::$trace = false;
        DirectorySyncBridge::$events = [];
        DirectorySyncBridge::$fail = '';
        DirectorySyncBridge::$afterSync = null;
        foreach (glob($this->directory.'/*') ?: [] as $path) {
            if (is_link($path) || is_file($path)) {
                unlink($path);
            } else {
                rmdir($path);
            }
        }
        rmdir($this->directory);
        parent::tearDown();
    }

    public function test_current_standalone_custody_fixture_completes_one_functional_flow(): void
    {
        $owned = $this->directory.'/owned-flow';
        self::assertTrue(mkdir($owned, 0700));
        $root = dirname(__DIR__, 2);
        $process = new Process([PHP_BINARY, $root.'/benchmarks/nzb-custody/flow.php'], $root, [
            'CUSTODY_VARIANT' => 'native', 'CUSTODY_OWNED' => $owned,
            'CUSTODY_COUNT' => '1', 'CUSTODY_WARMUP' => '0',
        ]);
        $process->setTimeout(20);
        $process->run();
        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $result = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        self::assertTrue($result['parity']);
        self::assertCount(1, $result['flow_ms']);
        self::assertDirectoryDoesNotExist($owned);
    }

    public function test_real_local_directory_sync_is_required_on_this_runtime(): void
    {
        self::assertContains(PHP_OS_FAMILY, ['Linux', 'Darwin']);
        self::assertTrue(function_exists('fsync'));
        self::assertTrue(DirectorySyncBridge::sync($this->directory, 'directory'));
        self::assertSame([['boundary' => 'directory', 'ok' => true]], DirectorySyncBridge::$events);
    }

    public function test_file_and_missing_path_fail_without_success_callback(): void
    {
        file_put_contents($this->directory.'/file', 'fixture');
        $called = 0;
        DirectorySyncBridge::$afterSync = static function () use (&$called): void {
            $called++;
        };
        self::assertFalse(DirectorySyncBridge::sync($this->directory.'/file', 'file'));
        self::assertFalse(DirectorySyncBridge::sync($this->directory.'/missing', 'missing'));
        self::assertSame(0, $called);
        self::assertSame([
            ['boundary' => 'file', 'ok' => false],
            ['boundary' => 'missing', 'ok' => false],
        ], DirectorySyncBridge::$events);
    }

    public function test_directory_symlink_succeeds_and_file_symlink_fails(): void
    {
        self::assertTrue(mkdir($this->directory.'/target', 0700));
        file_put_contents($this->directory.'/file', 'fixture');
        self::assertTrue(symlink($this->directory.'/target', $this->directory.'/directory-link'));
        self::assertTrue(symlink($this->directory.'/file', $this->directory.'/file-link'));
        self::assertTrue(DirectorySyncBridge::sync($this->directory.'/directory-link', 'directory-link'));
        self::assertFalse(DirectorySyncBridge::sync($this->directory.'/file-link', 'file-link'));
    }

    public function test_default_tracing_is_disabled_and_disabled_trace_does_not_accumulate(): void
    {
        $defaults = (new \ReflectionClass(DirectorySyncBridge::class))->getDefaultProperties();
        self::assertFalse($defaults['trace']);
        DirectorySyncBridge::$trace = false;
        for ($i = 0; $i < 100; $i++) {
            self::assertTrue(DirectorySyncBridge::sync($this->directory, 'directory'));
        }
        self::assertFalse(DirectorySyncBridge::sync($this->directory.'/missing', 'missing'));
        self::assertSame([], DirectorySyncBridge::$events);
    }

    public function test_success_callback_is_independent_of_tracing_and_receives_exact_boundary(): void
    {
        DirectorySyncBridge::$trace = false;
        $calls = [];
        DirectorySyncBridge::$afterSync = static function (string $directory, string $boundary) use (&$calls): void {
            $calls[] = [$directory, $boundary];
        };
        self::assertTrue(DirectorySyncBridge::sync($this->directory, 'success'));
        self::assertSame([[$this->directory, 'success']], $calls);
        DirectorySyncBridge::$fail = 'injected';
        self::assertFalse(DirectorySyncBridge::sync($this->directory, 'injected'));
        self::assertSame([[$this->directory, 'success']], $calls);
        self::assertSame([], DirectorySyncBridge::$events);
    }

    public function test_injection_precedes_path_validation_and_failure_does_not_call_success_hook(): void
    {
        DirectorySyncBridge::$fail = 'injected';
        DirectorySyncBridge::$afterSync = static function (): never {
            self::fail('A failed sync must not invoke the success hook.');
        };
        self::assertFalse(DirectorySyncBridge::sync('php://memory', 'injected'));
        self::assertSame([['boundary' => 'injected', 'ok' => false, 'injected' => true]], DirectorySyncBridge::$events);
    }

    public function test_trace_records_success_before_callback_and_later_failure_preserves_order(): void
    {
        DirectorySyncBridge::$afterSync = static function (): void {
            self::assertSame([['boundary' => 'first', 'ok' => true]], DirectorySyncBridge::$events);
        };
        self::assertTrue(DirectorySyncBridge::sync($this->directory, 'first'));
        self::assertFalse(DirectorySyncBridge::sync($this->directory.'/missing', 'second'));
        self::assertSame([
            ['boundary' => 'first', 'ok' => true],
            ['boundary' => 'second', 'ok' => false],
        ], DirectorySyncBridge::$events);
    }

    public function test_stream_wrapper_cannot_acknowledge_durability(): void
    {
        self::assertFalse(DirectorySyncBridge::sync('php://memory', 'wrapper'));
        self::assertSame([['boundary' => 'wrapper', 'ok' => false, 'failure' => 'unsupported']], DirectorySyncBridge::$events);
    }

    public function test_previous_error_handler_is_restored_after_success_and_native_failure(): void
    {
        $warnings = [];
        $handler = static function (int $severity, string $message) use (&$warnings): bool {
            $warnings[] = [$severity, $message];

            return true;
        };
        set_error_handler($handler);
        try {
            self::assertTrue(DirectorySyncBridge::sync($this->directory, 'success'));
            self::assertFalse(DirectorySyncBridge::sync($this->directory.'/missing', 'missing'));
            self::assertSame([], $warnings);
            $previous = set_error_handler(static fn (): bool => true);
            restore_error_handler();
            self::assertSame($handler, $previous);
            trigger_error('owned-fixture-warning', E_USER_WARNING);
            self::assertSame([[E_USER_WARNING, 'owned-fixture-warning']], $warnings);
        } finally {
            restore_error_handler();
        }
    }

    public function test_callback_exception_propagates_after_handler_restoration(): void
    {
        $handler = static fn (): bool => true;
        set_error_handler($handler);
        try {
            DirectorySyncBridge::$afterSync = static function (): never {
                throw new \RuntimeException('owned-fixture-callback');
            };
            try {
                DirectorySyncBridge::sync($this->directory, 'success');
                self::fail('The callback exception must propagate.');
            } catch (\RuntimeException $exception) {
                self::assertSame('owned-fixture-callback', $exception->getMessage());
            }
            $previous = set_error_handler(static fn (): bool => true);
            restore_error_handler();
            self::assertSame($handler, $previous);
            self::assertSame([['boundary' => 'success', 'ok' => true]], DirectorySyncBridge::$events);
        } finally {
            restore_error_handler();
        }
    }
}
