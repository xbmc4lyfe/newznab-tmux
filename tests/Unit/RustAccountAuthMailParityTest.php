<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Mail\AccountDeleted;
use App\Mail\AccountExpired;
use App\Mail\ForgottenPassword;
use App\Mail\NewAccountCreatedEmail;
use App\Mail\WelcomeEmail;
use App\Models\User;
use App\Support\RustPipelineLibrary;
use Closure;
use FFI;
use PHPUnit\Runner\Version;
use Random\Engine\Mt19937;
use Random\Randomizer;
use ReflectionProperty;
use Spatie\Permission\Models\Role;
use Tests\TestCase;
use Throwable;

/**
 * Wave 25.1: byte parity, real mailable callers, ABI safety and paired measurements.
 * Build: CARGO_TARGET_DIR=/tmp/cargo-w25-1 cargo build --manifest-path rust/pipeline-ffi/Cargo.toml --release
 * Run: NNTMUX_PIPELINE_FFI_LIB=/tmp/cargo-w25-1/release/libnntmux_pipeline.dylib php artisan test --compact tests/Unit/RustAccountAuthMailParityTest.php
 * Benchmarks always run; raw samples and build/source identities are retained in system temp.
 * Apple M5 / PHP 8.5.12 / rustc 1.99.1 release screen (2026-10-24):
 * worst-round helper common mean/uncommon individual-call p99 speedups:
 * AccountDeleted 0.14x/0.09x; AccountExpired 0.13x/0.08x;
 * ForgottenPassword 0.10x/0.10x; NewAccountCreatedEmail 0.14x/0.09x;
 * WelcomeEmail 0.15x/0.08x. Complete caller mean ratios span 0.57x-0.77x.
 * All five stay on PHP; these local CPU measurements do not establish transport latency.
 */
class RustAccountAuthMailParityTest extends TestCase
{
    /** @var list<class-string> */
    private const array CLASSES = [AccountDeleted::class, AccountExpired::class, ForgottenPassword::class, NewAccountCreatedEmail::class, WelcomeEmail::class];

    private const string CDEF = 'int32_t nntmux_w25_account_auth_mail(uint32_t operation, const char *input, size_t length, char *output, size_t capacity);';

    protected function setUp(): void
    {
        parent::setUp();
        foreach (self::CLASSES as $class) {
            (new ReflectionProperty($class, 'ffi'))->setValue(null, null);
            $class::setUseRustFfi(false);
        }
        config(['app.name' => 'NNTmux', 'mail.from.address' => 'noreply@example.test', 'mail.brand.subject_prefix' => '[NNTmux] ']);
    }

    protected function tearDown(): void
    {
        foreach (self::CLASSES as $class) {
            $class::setUseRustFfi(false);
            (new ReflectionProperty($class, 'ffi'))->setValue(null, null);
        }
        parent::tearDown();
    }

    public function test_defaults_and_independent_toggles(): void
    {
        foreach (self::CLASSES as $class) {
            self::assertFalse($class::usesRustFfi());
            self::assertFalse((new ReflectionProperty($class, 'useRustFfi'))->getDefaultValue());
            self::assertSame(self::CDEF, $class::FFI_CDEF);
            $class::setUseRustFfi(true);
            self::assertTrue($class::usesRustFfi());
            foreach (self::CLASSES as $other) {
                if ($class !== $other) {
                    self::assertFalse($other::usesRustFfi());
                }
            }
            $class::setUseRustFfi(false);
        }
    }

    private function native(): FFI
    {
        $path = getenv('NNTMUX_PIPELINE_FFI_LIB');
        $ffi = RustPipelineLibrary::resolveDomain('wave25-account-auth-mail-test', self::CDEF, $path === false || $path === '' ? null : $path);
        self::assertNotNull($ffi, 'Required native parity: build the release library and set NNTMUX_PIPELINE_FFI_LIB.');

        return $ffi;
    }

    /** @param list<?string> $parts */
    private static function frames(array $parts): string
    {
        $out = '';
        foreach ($parts as $part) {
            $out .= $part === null ? pack('V', 0xFFFFFFFF) : pack('V', strlen($part)).$part;
        }

        return $out;
    }

    private static function abi(FFI $ffi, mixed ...$args): int
    {
        // @phpstan-ignore method.notFound
        return $ffi->nntmux_w25_account_auth_mail(...$args);
    }

    /** @return list<array{string, string, string, string}> */
    private static function copyFixtures(): array
    {
        return [
            ['User account deleted', 'User alice deleted their NNTmux account.', 'emails.markdown.accountDeleted', 'username'],
            ['Your account has expired', 'Your NNTmux subscription has expired — account downgraded to User.', 'emails.markdown.accountExpired', 'username'],
            ['Reset your password', 'Reset your password using the secure link inside.', 'emails.markdown.forgottenPassword', 'resetLink'],
            ['New account registered', 'New user registered on NNTmux.', 'emails.markdown.newAccountCreated', 'username'],
            ['Welcome to NNTmux', 'Welcome to NNTmux — your account is ready.', 'emails.markdown.welcome', 'username'],
        ];
    }

    public function test_independent_copy_fixtures_and_raw_native_results(): void
    {
        $ffi = $this->native();
        foreach (self::CLASSES as $operation => $class) {
            [$subject, $preheader, , $field] = self::copyFixtures()[$operation];
            $viewData = [$field => 'alice'];
            if ($operation === 1) {
                $viewData['account'] = 'User';
            }
            $viewData += ['site' => 'NNTmux', 'preheader' => $preheader];
            $expected = ['subject' => $subject, 'preheader' => $preheader, 'viewData' => $viewData];
            $args = $operation === 1 ? ['alice', 'NNTmux', null] : ['alice', 'NNTmux'];
            self::assertSame($expected, $class::formatMailDataPhp(...$args));
            self::assertSame($expected, $class::formatMailDataNative(...$args));
            $input = self::frames($args);
            $output = self::frames([$subject, $preheader, 'alice', 'NNTmux', ...($operation === 1 ? ['User'] : [])]);
            $buffer = $ffi->new('char[1024]');
            self::assertSame(strlen($output), self::abi($ffi, $operation, $input, strlen($input), $buffer, 1024));
            self::assertSame($output, FFI::string($buffer, strlen($output)));
            foreach ([false, true] as $enabled) {
                $class::setUseRustFfi($enabled);
                self::assertSame($expected, $class::formatMailData(...$args));
            }
            $class::setUseRustFfi(false);
        }
    }

    public function test_byte_strings_empty_null_role_and_large_output_retry(): void
    {
        $this->native();
        foreach (['', '0', "é漢字<&>\"'", "a\0b\xff\n", str_repeat('long', 80_000)] as $value) {
            foreach (self::CLASSES as $operation => $class) {
                foreach ($operation === 1 ? [null, '', '0', $value] : [null] as $role) {
                    $args = $operation === 1 ? [$value, $value, $role] : [$value, $value];
                    $expected = $class::formatMailDataPhp(...$args);
                    self::assertSame($expected, $class::formatMailDataNative(...$args));
                    if ($operation === 1) {
                        self::assertSame($role ?? 'User', $expected['viewData']['account']);
                    }
                }
                self::assertInstanceOf(FFI::class, (new ReflectionProperty($class, 'ffi'))->getValue());
            }
        }
    }

    public function test_real_mailable_builds_preserve_copy_branding_and_public_overrides(): void
    {
        $this->native();
        $user = new User;
        $user->username = 'alice';
        $role = new Role;
        $role->name = 'Gold';
        $user->setRelation('role', $role);
        foreach ([false, true] as $enabled) {
            foreach (self::CLASSES as $operation => $class) {
                $class::setUseRustFfi($enabled);
                /** @var AccountDeleted|AccountExpired|ForgottenPassword|NewAccountCreatedEmail|WelcomeEmail $mail */
                $mail = new $class($operation === 2 ? 'https://example.test/reset?token=a&email=x%40y.test' : $user);
                [$subject, $preheader, $view, $field] = self::copyFixtures()[$operation];
                $preheader = $operation === 1 ? 'Your NNTmux subscription has expired — account downgraded to Gold.' : $preheader;
                self::assertSame($preheader, $mail->preheader);
                $mail->build();
                self::assertSame('[NNTmux] '.$subject, $mail->subject);
                self::assertSame($view, $mail->markdown);
                self::assertSame('noreply@example.test', $mail->from[0]['address']);
                self::assertSame($operation === 2 ? $mail->resetLink : 'alice', $mail->viewData[$field]);
                self::assertSame($preheader, $mail->viewData['preheader']);
                self::assertSame('NNTmux', $mail->viewData['site']);
                if ($operation === 1) {
                    self::assertSame('Gold', $mail->viewData['account']);
                }
                // Existing public-property mutations (including null) must survive build().
                $mail->site = 'Updated';
                $mail->preheader = null;
                config(['mail.brand.subject_prefix' => '']);
                $mail->build();
                self::assertSame($operation === 4 ? 'Welcome to Updated' : $subject, $mail->subject);
                self::assertNull($mail->viewData['preheader']);
                self::assertSame('Updated', $mail->viewData['site']);
                config(['mail.brand.subject_prefix' => '[NNTmux] ']);
            }
            $user->setRelation('role', null);
            self::assertSame('User', (new AccountExpired($user))->account);
            $user->setRelation('role', $role);
            config(['app.name' => ['Array Site']]);
            self::assertSame('Array Site', (new ForgottenPassword('link'))->site);
            config(['app.name' => null]);
            self::assertSame('', (new ForgottenPassword('link'))->site);
            config(['app.name' => 'NNTmux']);
        }
    }

    public function test_native_fallback_when_unavailable_or_symbol_throws(): void
    {
        foreach ([false, FFI::cdef('')] as $handle) {
            foreach (self::CLASSES as $operation => $class) {
                (new ReflectionProperty($class, 'ffi'))->setValue(null, $handle);
                $args = $operation === 1 ? ['u', 's', null] : ['u', 's'];
                $class::setUseRustFfi(true);
                self::assertSame($class::formatMailDataPhp(...$args), $class::formatMailData(...$args));
                self::assertSame($class::formatMailDataPhp(...$args), $class::formatMailDataNative(...$args));
            }
        }
    }

    public function test_direct_abi_short_buffers_regions_malformed_and_aliases(): void
    {
        $ffi = $this->native();
        $out = $ffi->new('char[1024]');
        foreach (self::CLASSES as $operation => $class) {
            $args = $operation === 1 ? ['alice', 'NNTmux', null] : ['alice', 'NNTmux'];
            $input = self::frames($args);
            [$subject, $preheader] = self::copyFixtures()[$operation];
            $expected = self::frames([$subject, $preheader, 'alice', 'NNTmux', ...($operation === 1 ? ['User'] : [])]);
            $needed = strlen($expected);
            FFI::memset($out, 90, 1024);
            foreach ([0, 1, $needed - 1] as $cap) {
                self::assertSame(-$needed, self::abi($ffi, $operation, $input, strlen($input), $out, $cap));
                self::assertSame(str_repeat('Z', 1024), FFI::string($out, 1024));
            }
            foreach ([[null, 0, $out, 1024], [null, 5, $out, 1024], [$input, strlen($input), null, 0], [$input, strlen($input), null, 10], [$input, -1, $out, 1024], [$input, strlen($input), $out, -1], [$ffi->cast('char *', -8), 16, $out, 1024], [$input, strlen($input), $ffi->cast('char *', -8), 16]] as $regions) {
                self::assertSame(-1, self::abi($ffi, $operation, ...$regions));
                self::assertSame(str_repeat('Z', 1024), FFI::string($out, 1024));
            }
            $bad = ['', substr($input, 0, -1), $input.'x', self::frames([...$args, 'extra']), self::frames([null, 's']), self::frames(['u', null]), pack('V', 100).'short', "\x01\x00\x00"];
            foreach ($bad as $bytes) {
                self::assertSame(-1, self::abi($ffi, $operation, $bytes, strlen($bytes), $out, 1024));
                self::assertSame(str_repeat('Z', 1024), FFI::string($out, 1024));
            }
            self::assertSame($needed, self::abi($ffi, $operation, $input, strlen($input), FFI::addr($out[1]), $needed));
            self::assertSame($expected, FFI::string(FFI::addr($out[1]), $needed));
            self::assertSame('Z', $out[0]);
            self::assertSame('Z', $out[$needed + 1]);
            $alias = $ffi->new('char[1024]');
            foreach ([[0, 0], [0, 1], [1, 0], [7, 3]] as [$inputOffset, $outputOffset]) {
                FFI::memset($alias, 81, 1024);
                FFI::memcpy(FFI::addr($alias[$inputOffset]), $input, strlen($input));
                $before = FFI::string($alias, 1024);
                self::assertSame($needed, self::abi($ffi, $operation, FFI::addr($alias[$inputOffset]), strlen($input), FFI::addr($alias[$outputOffset]), $needed));
                self::assertSame(substr($before, 0, $outputOffset).$expected.substr($before, $outputOffset + $needed), FFI::string($alias, 1024));
            }
        }
        FFI::memset($out, 90, 1024);
        foreach ([5, 99, 0xFFFFFFFF] as $operation) {
            self::assertSame(-1, self::abi($ffi, $operation, '', 0, $out, 1024));
            self::assertSame(str_repeat('Z', 1024), FFI::string($out, 1024));
        }
    }

    public function test_loader_unavailable_falls_back_without_using_a_shared_library(): void
    {
        $original = getenv('NNTMUX_PIPELINE_FFI_LIB');
        try {
            putenv('NNTMUX_PIPELINE_FFI_LIB=/nonexistent/wave25-1/libnntmux_pipeline.dylib');
            foreach (self::CLASSES as $operation => $class) {
                $args = $operation === 1 ? ['u', 's', null] : ['u', 's'];
                $class::setUseRustFfi(true);
                self::assertSame($class::formatMailDataPhp(...$args), $class::formatMailData(...$args));
                self::assertFalse((new ReflectionProperty($class, 'ffi'))->getValue());
            }
        } finally {
            $original === false ? putenv('NNTMUX_PIPELINE_FFI_LIB') : putenv('NNTMUX_PIPELINE_FFI_LIB='.$original);
        }
    }

    public function test_paired_randomized_benchmarks(): void
    {
        $this->native();
        $artifact = tempnam(sys_get_temp_dir(), 'nntmux-w25-1-mail-');
        self::assertNotFalse($artifact);
        $evidence = [
            'protocol' => [
                'rounds' => 3, 'paired_samples_per_round' => 1001, 'calls_per_sample' => 1, 'warmup_calls_per_implementation' => 101,
                'order' => 'seeded Mt19937 randomized PHP/Rust pairs, identical fixture per pair',
                'p99' => 'nearest-rank individual-call p99 of 1001 samples per implementation per round; includes timer/callback overhead',
                'uncertainty' => 'all three round ratios plus min/max range retained; no claim of production p99 or confidence interval',
                'gate' => 'every common mean and uncommon p99 must reach 1.5x in every round; incomplete evidence cannot enable Rust',
                'scope' => 'formatMailData dispatch plus complete mailable constructor/build callers; FFI, framing, allocation and decoding included; no network/transport',
                'build' => 'CARGO_TARGET_DIR=/tmp/cargo-w25-1 cargo build --manifest-path rust/pipeline-ffi/Cargo.toml --release',
                'reproduce' => 'NNTMUX_PIPELINE_FFI_LIB=/tmp/cargo-w25-1/release/libnntmux_pipeline.dylib php artisan test --compact tests/Unit/RustAccountAuthMailParityTest.php',
            ],
            'metadata' => self::benchmarkMetadata(), 'measurements' => [], 'summary' => [], 'failures' => [],
        ];
        fwrite(STDERR, 'Wave25.1 raw benchmark artifact: '.$artifact.PHP_EOL);
        try {
            foreach (self::CLASSES as $operation => $class) {
                $qualified = true;
                foreach (['common', 'uncommon'] as $kind) {
                    $site = $kind === 'common' ? 'NNTmux' : str_repeat('Indexer é ', 200);
                    $value = $operation === 2 ? 'https://example.test/reset?token=abc&email=x%40y.test' : 'alice';
                    $value = $kind === 'common' ? $value : $value.str_repeat('x', 4096);
                    $role = $kind === 'common' ? null : str_repeat('Premium ', 200);
                    $args = $operation === 1 ? [$value, $site, $role] : [$value, $site];
                    config(['app.name' => $site]);
                    $user = new User;
                    $user->username = $value;
                    $roleModel = new Role;
                    $roleModel->name = $role ?? 'User';
                    $user->setRelation('role', $roleModel);
                    $calls = [
                        'formatMailData' => static fn (): array => $class::formatMailData(...$args),
                        'constructor_build' => static function () use ($class, $operation, $value, $user): array {
                            $mail = new $class($operation === 2 ? $value : $user);
                            $mail->build();

                            return [$mail->subject, $mail->markdown, $mail->viewData, $mail->preheader];
                        },
                    ];
                    foreach ($calls as $method => $call) {
                        $name = $class.'::'.$method.'/'.$kind;
                        $class::setUseRustFfi(false);
                        $expected = $call();
                        $class::setUseRustFfi(true);
                        self::assertSame($expected, $call(), $name.' correctness before timing');
                        // A direct ABI assertion and a loaded handle prevent PHP fallback masking native absence.
                        self::assertInstanceOf(FFI::class, (new ReflectionProperty($class, 'ffi'))->getValue());
                        $input = self::frames($args);
                        $ffi = $this->native();
                        $buffer = $ffi->new('char[32768]');
                        self::assertGreaterThan(0, self::abi($ffi, $operation, $input, strlen($input), $buffer, 32768));
                        $ratios = [];
                        for ($round = 0; $round < 3; $round++) {
                            $seed = 250100 + $round + (int) (crc32($name) & 0xFFFF);
                            $index = count($evidence['measurements']);
                            $evidence['measurements'][$index] = ['name' => $name, 'round' => $round, 'seed' => $seed, 'fixture_sha256' => hash('sha256', serialize($args)), 'warmup' => [], 'pairs' => []];
                            $record = &$evidence['measurements'][$index];
                            $stats = self::measurePair($class, $call, $seed, $record);
                            $record['stats'] = $stats;
                            unset($record);
                            $ratios[] = $stats;
                        }
                        $means = array_column($ratios, 'mean_speedup');
                        $tails = array_column($ratios, 'p99_speedup');
                        $passed = min($means) >= 1.5 && min($tails) >= 1.5;
                        $qualified = $qualified && $passed;
                        $evidence['summary'][$name] = ['mean_speedup_range' => [min($means), max($means)], 'p99_speedup_range' => [min($tails), max($tails)], 'qualified' => $passed];
                        fwrite(STDERR, $name.' '.json_encode($evidence['summary'][$name], JSON_THROW_ON_ERROR).PHP_EOL);
                    }
                }
                $class::setUseRustFfi(false);
                $evidence['class_defaults'][$class] = ['qualified' => $qualified, 'source_default' => (new ReflectionProperty($class, 'useRustFfi'))->getDefaultValue()];
                if (! $qualified) {
                    self::assertFalse((new ReflectionProperty($class, 'useRustFfi'))->getDefaultValue(), 'Below-threshold measurements require PHP default');
                }
            }
        } catch (Throwable $failure) {
            $evidence['failures'][] = ['class' => $failure::class, 'message' => $failure->getMessage()];
            throw $failure;
        } finally {
            foreach (self::CLASSES as $class) {
                $class::setUseRustFfi(false);
            }
            $evidence['finished_at_utc'] = gmdate('c');
            self::assertNotFalse(file_put_contents($artifact, json_encode($evidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR)));
            fwrite(STDERR, 'Wave25.1 evidence preserved: '.$artifact.PHP_EOL);
        }
    }

    /**
     * @param  class-string  $class
     * @param  array<string, mixed>  $record
     * @return array<string, float>
     */
    private static function measurePair(string $class, Closure $call, int $seed, array &$record): array
    {
        $times = ['php' => [], 'rust' => []];
        foreach (['php', 'rust'] as $implementation) {
            $class::setUseRustFfi($implementation === 'rust');
            $start = hrtime(true);
            for ($i = 0; $i < 101; $i++) {
                $call();
            }
            $record['warmup'][] = ['implementation' => $implementation, 'calls' => 101, 'elapsed_ns' => hrtime(true) - $start];
        }
        $randomizer = new Randomizer(new Mt19937($seed));
        for ($sample = 0; $sample < 1001; $sample++) {
            $order = $randomizer->getInt(0, 1) === 0 ? ['php', 'rust'] : ['rust', 'php'];
            $record['pairs'][$sample] = ['order' => $order, 'elapsed_ns' => []];
            foreach ($order as $implementation) {
                $class::setUseRustFfi($implementation === 'rust');
                $start = hrtime(true);
                $call();
                $elapsed = hrtime(true) - $start;
                $record['pairs'][$sample]['elapsed_ns'][$implementation] = $elapsed;
                $times[$implementation][] = $elapsed;
            }
        }
        $class::setUseRustFfi(false);
        $stats = [];
        foreach ($times as $implementation => $values) {
            sort($values);
            $stats[$implementation.'_mean_ns'] = array_sum($values) / count($values);
            $stats[$implementation.'_p99_ns'] = (float) $values[(int) ceil(0.99 * count($values)) - 1];
        }
        $stats['mean_speedup'] = $stats['php_mean_ns'] / $stats['rust_mean_ns'];
        $stats['p99_speedup'] = $stats['php_p99_ns'] / $stats['rust_p99_ns'];

        return $stats;
    }

    /** @return array<string, mixed> */
    private static function benchmarkMetadata(): array
    {
        $root = dirname(__DIR__, 2);
        $files = [__FILE__, (string) getenv('NNTMUX_PIPELINE_FFI_LIB'), $root.'/composer.lock', $root.'/vendor/composer/installed.php', $root.'/rust/pipeline-ffi/Cargo.lock', $root.'/rust/pipeline-ffi/Cargo.toml', $root.'/app/Support/RustPipelineLibrary.php', $root.'/rust/pipeline-ffi/src/cleaning.rs'];
        foreach (self::CLASSES as $class) {
            $source = (new \ReflectionClass($class))->getFileName();
            if ($source !== false) {
                $files[] = $source;
            }
        }
        $suffix = PHP_OS_FAMILY === 'Darwin' ? 'dylib' : 'so';
        foreach ([$root.'/storage/app/nntmux-native/libnntmux_pipeline.'.$suffix, $root.'/rust/pipeline-ffi/target/release/libnntmux_pipeline.'.$suffix] as $library) {
            $files[] = $library;
        }
        $hashes = [];
        foreach ($files as $file) {
            $hashes[$file] = is_file($file) ? hash_file('sha256', $file) : 'absent';
        }

        return [
            'started_at_utc' => gmdate('c'), 'os' => php_uname(), 'architecture' => php_uname('m'),
            'cpu' => PHP_OS_FAMILY === 'Darwin' ? trim((string) shell_exec('sysctl -n machdep.cpu.brand_string 2>/dev/null')) : (is_readable('/proc/cpuinfo') ? file_get_contents('/proc/cpuinfo') : 'unavailable'),
            'php_version' => PHP_VERSION, 'php_binary' => PHP_BINARY, 'phpunit' => Version::id(),
            'ffi_extension' => phpversion('FFI'), 'ffi_enable' => ini_get('ffi.enable'), 'memory_limit' => ini_get('memory_limit'),
            'opcache_cli' => ini_get('opcache.enable_cli'), 'jit' => ini_get('opcache.jit'), 'load_average' => sys_getloadavg(),
            'rustc' => trim((string) shell_exec('rustc --version 2>&1')), 'cargo' => trim((string) shell_exec('cargo --version 2>&1')),
            'build_profile' => 'release (opt-level 3, lto, codegen-units 1)',
            'git_head' => trim((string) shell_exec('git rev-parse HEAD 2>&1')), 'git_status' => (string) shell_exec('git status --short 2>&1'),
            'source_sha256' => $hashes, 'host_ulimits' => (string) shell_exec('ulimit -a 2>&1'),
        ];
    }
}
