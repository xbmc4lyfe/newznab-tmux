<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Jobs\SendAccountDeletedEmail;
use App\Jobs\SendAccountExpiredEmail;
use App\Jobs\SendNewRegisteredAccountMail;
use App\Jobs\SendPasswordForgottenEmail;
use App\Jobs\SendWelcomeEmail;
use App\Mail\AccountDeleted;
use App\Mail\AccountExpired;
use App\Mail\ForgottenPassword;
use App\Mail\NewAccountCreatedEmail;
use App\Mail\WelcomeEmail;
use App\Models\User;
use App\Support\RustPipelineLibrary;
use Closure;
use FFI;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Runner\Version;
use Random\Engine\Mt19937;
use Random\Randomizer;
use ReflectionMethod;
use ReflectionProperty;
use Spatie\Permission\Models\Role;
use Symfony\Component\Process\Process;
use Tests\TestCase;
use Throwable;

/**
 * Wave 27.1 byte parity, real handlers, fallback/ABI safety and paired CPU screen.
 * Build: CARGO_TARGET_DIR=/tmp/cargo-w27-1 cargo build --manifest-path rust/pipeline-ffi/Cargo.toml --release
 * Run: NNTMUX_PIPELINE_FFI_LIB=/tmp/cargo-w27-1/release/libnntmux_pipeline.dylib php artisan test --compact tests/Unit/RustAccountMailDispatchJobsParityTest.php
 * Raw randomized samples, failures and source/build identities are saved to system temp.
 * Each of three rounds uses 1001 single-call pairs after 101 warmups per implementation.
 * Native adoption requires >=1.5x common mean and uncommon p99 for every helper/caller.
 * Callers use MailFake and build the captured mailable: queue, Blade and network are excluded.
 * Apple M5 / PHP 8.5.12 / rustc 1.99.1 release screen (2026-10-24):
 * worst-round helper common mean / uncommon individual-call p99 speedups:
 * deleted 0.10x/0.03x; expired 0.11x/0.02x; registered 0.12x/0.02x;
 * forgotten password 0.10x/0.02x; welcome 0.12x/0.05x. All defaults stay false.
 * Real caller common means and uncommon p99 also miss 1.5x for every job.
 * These local CPU measurements do not establish production delivery performance.
 */
class RustAccountMailDispatchJobsParityTest extends TestCase
{
    private const string CDEF = 'int32_t nntmux_w27_account_mail_dispatch_jobs(uint32_t operation, const char *input, size_t length, char *output, size_t capacity);';

    /** @var list<class-string<SendAccountDeletedEmail|SendAccountExpiredEmail|SendNewRegisteredAccountMail|SendPasswordForgottenEmail|SendWelcomeEmail>> */
    private const array CLASSES = [SendAccountDeletedEmail::class, SendAccountExpiredEmail::class, SendNewRegisteredAccountMail::class, SendPasswordForgottenEmail::class, SendWelcomeEmail::class];

    protected function setUp(): void
    {
        parent::setUp();
        $this->resetNativeState();
        config(['app.name' => 'NNTmux', 'mail.from.address' => 'noreply@example.test', 'mail.brand.subject_prefix' => '[NNTmux] ']);
    }

    protected function tearDown(): void
    {
        $this->resetNativeState();
        parent::tearDown();
    }

    private function resetNativeState(): void
    {
        foreach (self::CLASSES as $class) {
            $class::setUseRustFfi(false);
            (new ReflectionProperty($class, 'ffi'))->setValue(null, null);
        }
    }

    private function native(): FFI
    {
        $path = getenv('NNTMUX_PIPELINE_FFI_LIB');
        $ffi = RustPipelineLibrary::resolveDomain('wave27-account-mail-dispatch-jobs-test', self::CDEF, $path === false || $path === '' ? null : $path);
        self::assertNotNull($ffi, 'Native parity requires the release library and NNTMUX_PIPELINE_FFI_LIB.');

        return $ffi;
    }

    /** @param list<?string> $parts */
    private static function frames(array $parts): string
    {
        $result = '';
        foreach ($parts as $part) {
            $result .= $part === null ? pack('V', 0xFFFFFFFF) : pack('V', strlen($part)).$part;
        }

        return $result;
    }

    private static function abi(FFI $ffi, mixed ...$args): int
    {
        // @phpstan-ignore method.notFound
        return $ffi->nntmux_w27_account_mail_dispatch_jobs(...$args);
    }

    private static function user(): User
    {
        $user = new User;
        $user->username = 'alice';
        $user->email = 'alice@example.test';
        $role = new Role;
        $role->name = 'Gold';
        $user->setRelation('role', $role);

        return $user;
    }

    public function test_php_contract_defaults_and_independent_toggles(): void
    {
        foreach (self::CLASSES as $class) {
            self::assertFalse($class::usesRustFfi());
            self::assertFalse((new ReflectionProperty($class, 'useRustFfi'))->getDefaultValue());
            foreach ([null, '', '0', ' alice@example.test ', "a\0\xff"] as $recipient) {
                $args = $class === SendPasswordForgottenEmail::class ? [$recipient, ''] : [$recipient];
                $expected = ['recipient' => $recipient];
                if ($class === SendPasswordForgottenEmail::class) {
                    $expected['resetLink'] = '';
                }
                $expected['eligible'] = true;
                self::assertSame($expected, $class::normalizeDispatchPhp(...$args));
                self::assertSame($expected, $class::normalizeDispatch(...$args));
                self::assertSame($recipient, $class::normalizeRecipientPhp($recipient));
                self::assertTrue($class::evaluateEligibilityPhp(...$args));
                self::assertTrue($class::evaluateEligibility(...$args));
            }
            $class::setUseRustFfi(true);
            self::assertTrue($class::usesRustFfi());
            foreach (self::CLASSES as $other) {
                if ($class !== $other) {
                    self::assertFalse($other::usesRustFfi());
                }
            }
            $class::setUseRustFfi(false);
        }
        self::assertSame("/reset?token=a&b=0\0\xff", SendPasswordForgottenEmail::normalizeResetLinkPhp("/reset?token=a&b=0\0\xff"));
    }

    public function test_native_bytes_nulls_long_payloads_and_toggle_routing(): void
    {
        $this->native();
        foreach (self::CLASSES as $class) {
            foreach ([false, true] as $enabled) {
                $class::setUseRustFfi($enabled);
                foreach ([null, '', '0', ' alice@example.test ', "é漢字<&>\"'", "a\0b\xff\n", str_repeat('long', 80_000)] as $value) {
                    $args = $class === SendPasswordForgottenEmail::class ? [$value, $value ?? ''] : [$value];
                    self::assertSame($class::normalizeDispatchPhp(...$args), $class::normalizeDispatchNative(...$args));
                    self::assertSame($class::normalizeDispatchPhp(...$args), $class::normalizeDispatch(...$args));
                    self::assertSame($value, $class::normalizeRecipientNative($value));
                    self::assertSame($value, $class::normalizeRecipient($value));
                    self::assertTrue($class::evaluateEligibilityNative(...$args));
                    self::assertTrue($class::evaluateEligibility(...$args));
                    if ($class === SendPasswordForgottenEmail::class) {
                        self::assertSame($value ?? '', $class::normalizeResetLinkNative($value ?? ''));
                        self::assertSame($value ?? '', $class::normalizeResetLink($value ?? ''));
                    }
                }
            }
            self::assertInstanceOf(FFI::class, (new ReflectionProperty($class, 'ffi'))->getValue());
        }
    }

    public function test_real_handlers_send_once_using_current_config_model_and_exact_link(): void
    {
        $this->native();
        foreach ([false, true] as $enabled) {
            $user = self::user();
            $link = "https://site.test/reset?token=a%2Fb&email=a+b%40example.test#0\0\xff";
            $jobs = [new SendAccountDeletedEmail($user), new SendAccountExpiredEmail($user), new SendNewRegisteredAccountMail($user), new SendPasswordForgottenEmail($user, $link), new SendWelcomeEmail($user)];
            $user->email = 'updated@example.test';
            $user->username = 'updated';
            config(['mail.from.address' => 'updated-admin@example.test']);
            foreach (self::CLASSES as $class) {
                $class::setUseRustFfi($enabled);
            }
            Mail::fake();
            foreach ($jobs as $job) {
                $job->handle();
            }
            Mail::assertSentCount(5);
            Mail::assertNothingQueued();
            foreach ([AccountDeleted::class, AccountExpired::class, NewAccountCreatedEmail::class, ForgottenPassword::class, WelcomeEmail::class] as $index => $mailClass) {
                Mail::assertSent($mailClass, static function ($mail) use ($index, $link): bool {
                    $mail->build();
                    $recipient = in_array($index, [0, 2], true) ? 'updated-admin@example.test' : 'updated@example.test';
                    self::assertTrue($mail->hasTo($recipient));
                    self::assertCount(1, $mail->to);
                    self::assertSame('updated-admin@example.test', $mail->from[0]['address']);
                    if ($mail instanceof ForgottenPassword) {
                        self::assertSame($link, $mail->resetLink);
                        self::assertSame($link, $mail->viewData['resetLink']);
                    } else {
                        self::assertSame('updated', $mail->username);
                    }
                    if ($mail instanceof AccountExpired) {
                        self::assertSame('Gold', $mail->account);
                    }
                    self::assertStringStartsWith('[NNTmux] ', $mail->subject);

                    return true;
                });
            }
        }
    }

    public function test_real_handlers_retain_unconditional_dispatch_for_null_and_empty_addresses(): void
    {
        $this->native();
        foreach ([false, true] as $enabled) {
            foreach ([null, '', '0'] as $recipient) {
                $user = self::user();
                $user->email = $recipient;
                config(['mail.from.address' => $recipient]);
                Mail::fake();
                foreach (self::CLASSES as $class) {
                    $class::setUseRustFfi($enabled);
                    $job = $class === SendPasswordForgottenEmail::class ? new $class($user, '') : new $class($user);
                    $job->handle();
                }
                Mail::assertSentCount(5);
                Mail::assertNothingQueued();
            }
        }
    }

    private function assertFallbackResults(): void
    {
        foreach (self::CLASSES as $class) {
            foreach ([null, '', '0', "\0\xff"] as $recipient) {
                $args = $class === SendPasswordForgottenEmail::class ? [$recipient, "link\0\xff"] : [$recipient];
                self::assertSame($class::normalizeDispatchPhp(...$args), $class::normalizeDispatchNative(...$args));
                self::assertSame($class::normalizeDispatchPhp(...$args), $class::normalizeDispatch(...$args));
                self::assertSame($recipient, $class::normalizeRecipientNative($recipient));
                self::assertSame($recipient, $class::normalizeRecipient($recipient));
                self::assertTrue($class::evaluateEligibilityNative(...$args));
                self::assertTrue($class::evaluateEligibility(...$args));
            }
        }
        self::assertSame("link\0\xff", SendPasswordForgottenEmail::normalizeResetLinkNative("link\0\xff"));
        self::assertSame("link\0\xff", SendPasswordForgottenEmail::normalizeResetLink("link\0\xff"));
    }

    public function test_unavailable_and_throwing_ffi_fall_back(): void
    {
        foreach ([false, FFI::cdef('')] as $handle) {
            foreach (self::CLASSES as $class) {
                $class::setUseRustFfi(true);
                (new ReflectionProperty($class, 'ffi'))->setValue(null, $handle);
            }
            $this->assertFallbackResults();
        }
    }

    public function test_missing_library_falls_back(): void
    {
        $original = getenv('NNTMUX_PIPELINE_FFI_LIB');
        try {
            putenv('NNTMUX_PIPELINE_FFI_LIB=/nonexistent/wave27-1/libnntmux_pipeline.dylib');
            foreach (self::CLASSES as $class) {
                $class::setUseRustFfi(true);
            }
            $this->assertFallbackResults();
            foreach (self::CLASSES as $class) {
                self::assertFalse((new ReflectionProperty($class, 'ffi'))->getValue());
            }
        } finally {
            $original === false ? putenv('NNTMUX_PIPELINE_FFI_LIB') : putenv('NNTMUX_PIPELINE_FFI_LIB='.$original);
        }
    }

    public function test_ffi_disabled_in_fresh_process_falls_back(): void
    {
        $script = <<<'SCRIPT'
require getcwd().'/tests/bootstrap.php';
$classes = [App\Jobs\SendAccountDeletedEmail::class, App\Jobs\SendAccountExpiredEmail::class, App\Jobs\SendNewRegisteredAccountMail::class, App\Jobs\SendPasswordForgottenEmail::class, App\Jobs\SendWelcomeEmail::class];
foreach ($classes as $class) {
    $class::setUseRustFfi(true);
    $args = $class === App\Jobs\SendPasswordForgottenEmail::class ? [null, "link\0\xff"] : [null];
    if ($class::normalizeDispatch(...$args) !== $class::normalizeDispatchPhp(...$args)
        || $class::normalizeRecipient('0') !== '0' || !$class::evaluateEligibility(...$args)) {
        exit(1);
    }
}
exit(App\Jobs\SendPasswordForgottenEmail::normalizeResetLink("link\0\xff") === "link\0\xff" ? 0 : 1);
SCRIPT;
        $process = new Process([PHP_BINARY, '-d', 'ffi.enable=false', '-r', $script], dirname(__DIR__, 2));
        $process->run();
        self::assertSame(0, $process->getExitCode(), $process->getOutput().$process->getErrorOutput());
    }

    public function test_invalid_decoded_frames_are_rejected(): void
    {
        foreach (self::CLASSES as $class) {
            $decoder = new ReflectionMethod($class, 'decodeFrames');
            foreach ([null, '', "\x01\0\0", pack('V', 99).'short', self::frames(['one']), self::frames(['one', 'two', 'extra'])] as $output) {
                self::assertNull($decoder->invoke(null, $output, 2));
            }
        }
    }

    public function test_direct_abi_errors_capacity_canaries_and_overlap(): void
    {
        $ffi = $this->native();
        $out = $ffi->new('char[1024]');
        for ($operation = 0; $operation <= 4; $operation++) {
            foreach ([null, '', '0', "é漢字\0\xff"] as $recipient) {
                $args = $operation === 3 ? [$recipient, "link\0\xff"] : [$recipient];
                $input = self::frames($args);
                $expected = self::frames([...$args, '1']);
                $needed = strlen($expected);
                FFI::memset($out, 90, 1024);
                foreach ([0, 1, $needed - 1] as $capacity) {
                    self::assertSame(-$needed, self::abi($ffi, $operation, $input, strlen($input), $out, $capacity));
                    self::assertSame(str_repeat('Z', 1024), FFI::string($out, 1024));
                }
                foreach ([[null, 0, $out, 1024], [null, 8, $out, 1024], [$input, strlen($input), null, 0], [$input, strlen($input), null, 10], [$input, -1, $out, 1024], [$input, strlen($input), $out, -1], [$ffi->cast('char *', -8), 16, $out, 1024], [$input, strlen($input), $ffi->cast('char *', -8), 16]] as $regions) {
                    self::assertSame(-1, self::abi($ffi, $operation, ...$regions));
                    self::assertSame(str_repeat('Z', 1024), FFI::string($out, 1024));
                }
                foreach (['', substr($input, 0, -1), $input.'x', self::frames([...$args, 'extra']), pack('V', 100).'short', "\x01\0\0"] as $bad) {
                    self::assertSame(-1, self::abi($ffi, $operation, $bad, strlen($bad), $out, 1024));
                    self::assertSame(str_repeat('Z', 1024), FFI::string($out, 1024));
                }
                self::assertSame($needed, self::abi($ffi, $operation, $input, strlen($input), FFI::addr($out[1]), $needed));
                self::assertSame($expected, FFI::string(FFI::addr($out[1]), $needed));
                self::assertSame('Z', $out[0]);
                self::assertSame('Z', $out[$needed + 1]);
                foreach ([[0, 0], [0, 1], [1, 0], [7, 3]] as [$sourceOffset, $targetOffset]) {
                    FFI::memset($out, 81, 1024);
                    FFI::memcpy(FFI::addr($out[$sourceOffset]), $input, strlen($input));
                    $before = FFI::string($out, 1024);
                    self::assertSame(-$needed, self::abi($ffi, $operation, FFI::addr($out[$sourceOffset]), strlen($input), FFI::addr($out[$targetOffset]), $needed - 1));
                    self::assertSame($before, FFI::string($out, 1024));
                    self::assertSame($needed, self::abi($ffi, $operation, FFI::addr($out[$sourceOffset]), strlen($input), FFI::addr($out[$targetOffset]), $needed));
                    self::assertSame(substr($before, 0, $targetOffset).$expected.substr($before, $targetOffset + $needed), FFI::string($out, 1024));
                }
            }
        }
        foreach ([[99, []], [3, [null, null]], [3, ['u']], [4, ['u', 'extra']]] as [$operation, $args]) {
            $input = self::frames($args);
            FFI::memset($out, 90, 1024);
            self::assertSame(-1, self::abi($ffi, $operation, $input, strlen($input), $out, 1024));
            self::assertSame(str_repeat('Z', 1024), FFI::string($out, 1024));
        }
    }

    public function test_paired_randomized_benchmarks(): void
    {
        $ffi = $this->native();
        $artifact = tempnam(sys_get_temp_dir(), 'nntmux-w27-1-mail-');
        self::assertNotFalse($artifact);
        $evidence = [
            'protocol' => [
                'rounds' => 3, 'paired_samples_per_round' => 1001, 'calls_per_sample' => 1, 'warmup_calls_per_implementation' => 101,
                'order' => 'seeded Mt19937 randomized PHP/Rust pairs; identical fixture per pair',
                'p99' => 'nearest-rank single-call p99 per round (1001 samples), including callback/timer overhead',
                'uncertainty' => 'retain all rounds and ratio ranges; local CPU screen without production p99 or confidence interval claims',
                'gate' => 'every helper/caller common mean and uncommon p99 must be >=1.5x in every round; otherwise source default stays false',
                'scope' => 'PHP dispatch, framing, FFI, allocation/decoding and assembly; caller adds construction/handle/build with MailFake; excludes queue/Blade/network',
                'build' => 'CARGO_TARGET_DIR=/tmp/cargo-w27-1 cargo build --manifest-path rust/pipeline-ffi/Cargo.toml --release',
                'reproduce' => 'NNTMUX_PIPELINE_FFI_LIB=/tmp/cargo-w27-1/release/libnntmux_pipeline.dylib php artisan test --compact tests/Unit/RustAccountMailDispatchJobsParityTest.php',
            ],
            'metadata' => self::benchmarkMetadata(), 'measurements' => [], 'summary' => [], 'failures' => [],
        ];
        fwrite(STDERR, 'Wave27.1 raw benchmark artifact: '.$artifact.PHP_EOL);
        try {
            foreach (self::CLASSES as $operation => $class) {
                $qualified = true;
                foreach (['common', 'uncommon'] as $kind) {
                    $value = $kind === 'common' ? 'alice@example.test' : str_repeat("é漢字\0\xff", 500);
                    $site = $kind === 'common' ? 'NNTmux' : str_repeat('Indexer é ', 200);
                    $link = $kind === 'common' ? 'https://site.test/reset?token=abc&email=alice%40example.test' : 'https://site.test/reset?token='.str_repeat("a%2Fb<&>\0\xff", 8192);
                    $user = self::user();
                    $user->username = $value;
                    // Real callers use valid addresses; scalar helpers also exercise arbitrary bytes.
                    config(['app.name' => $site, 'mail.brand.subject_prefix' => '['.$site.'] ']);
                    $args = $operation === 3 ? [$value, $link] : [$value];
                    $helper = static fn (): array => $class::normalizeDispatch(...$args);
                    $caller = static function () use ($operation, $user, $link): array {
                        $fake = Mail::fake();
                        $job = match ($operation) {
                            0 => new SendAccountDeletedEmail($user),
                            1 => new SendAccountExpiredEmail($user),
                            2 => new SendNewRegisteredAccountMail($user),
                            3 => new SendPasswordForgottenEmail($user, $link),
                            4 => new SendWelcomeEmail($user),
                        };
                        $job->handle();
                        $mailClass = match ($operation) {
                            0 => AccountDeleted::class, 1 => AccountExpired::class,
                            2 => NewAccountCreatedEmail::class, 3 => ForgottenPassword::class, 4 => WelcomeEmail::class,
                        };
                        $mail = $fake->sent($mailClass)->sole();
                        $mail->build();

                        return [$mail->subject, $mail->markdown, $mail->viewData, $mail->to, $mail->from];
                    };
                    foreach (['helper' => $helper, 'caller' => $caller] as $method => $call) {
                        $name = $class.'::'.$method.'/'.$kind;
                        $class::setUseRustFfi(false);
                        $expected = $call();
                        $class::setUseRustFfi(true);
                        self::assertSame($expected, $call(), $name.' parity before timing');
                        self::assertInstanceOf(FFI::class, (new ReflectionProperty($class, 'ffi'))->getValue());
                        // Require native success, avoiding measurements of an unavailable FFI fallback.
                        $input = self::frames($args);
                        $capacity = strlen($input) + 1024;
                        $out = $ffi->new('char['.$capacity.']');
                        self::assertSame(strlen($input) + 5, self::abi($ffi, $operation, $input, strlen($input), $out, $capacity));
                        self::assertSame(self::frames([...$args, '1']), FFI::string($out, strlen($input) + 5));
                        $ratios = [];
                        for ($round = 0; $round < 3; $round++) {
                            $seed = 270100 + $round + (int) (crc32($name) & 0xFFFF);
                            $index = count($evidence['measurements']);
                            $evidence['measurements'][$index] = ['name' => $name, 'round' => $round, 'seed' => $seed, 'fixture_sha256' => hash('sha256', serialize($method === 'helper' ? $args : [$operation, $user->getAttributes(), 'Gold', $link, $site, config('mail.from.address')])), 'warmup' => [], 'pairs' => []];
                            $record = &$evidence['measurements'][$index];
                            $stats = self::measurePair($class, $call, $expected, $seed, $record);
                            $record['stats'] = $stats;
                            unset($record);
                            $ratios[] = $stats;
                        }
                        $means = array_column($ratios, 'mean_speedup');
                        $tails = array_column($ratios, 'p99_speedup');
                        $passed = $kind === 'common' ? min($means) >= 1.5 : min($tails) >= 1.5;
                        $qualified = $qualified && $passed;
                        $evidence['summary'][$name] = ['mean_speedup_range' => [min($means), max($means)], 'p99_speedup_range' => [min($tails), max($tails)], 'qualified' => $passed];
                        fwrite(STDERR, $name.' '.json_encode($evidence['summary'][$name], JSON_THROW_ON_ERROR).PHP_EOL);
                    }
                }
                $class::setUseRustFfi(false);
                $default = (new ReflectionProperty($class, 'useRustFfi'))->getDefaultValue();
                $evidence['class_defaults'][$class] = ['qualified' => $qualified, 'source_default' => $default];
                if (! $qualified) {
                    self::assertFalse($default, $class.' cannot default to Rust without qualifying evidence');
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
            fwrite(STDERR, 'Wave27.1 evidence preserved: '.$artifact.PHP_EOL);
        }
    }

    /**
     * @param  class-string<SendAccountDeletedEmail|SendAccountExpiredEmail|SendNewRegisteredAccountMail|SendPasswordForgottenEmail|SendWelcomeEmail>  $class
     * @param  array<mixed>  $expected
     * @param  array<string, mixed>  $record
     * @return array<string, float>
     */
    private static function measurePair(string $class, Closure $call, array $expected, int $seed, array &$record): array
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
                $actual = $call();
                $elapsed = hrtime(true) - $start;
                // Verify every observation after stopping the timer; mismatches invalidate the run.
                self::assertSame($expected, $actual);
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
        $files = [__FILE__, (string) getenv('NNTMUX_PIPELINE_FFI_LIB'), $root.'/composer.lock', $root.'/vendor/composer/installed.php', $root.'/rust/pipeline-ffi/Cargo.lock', $root.'/rust/pipeline-ffi/Cargo.toml', $root.'/app/Support/RustPipelineLibrary.php', $root.'/rust/pipeline-ffi/src/cleaning.rs', $root.'/app/Mail/Concerns/HasBrandedSubject.php'];
        foreach ([...self::CLASSES, AccountDeleted::class, AccountExpired::class, NewAccountCreatedEmail::class, ForgottenPassword::class, WelcomeEmail::class] as $class) {
            $source = (new \ReflectionClass($class))->getFileName();
            if ($source !== false) {
                $files[] = $source;
            }
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
