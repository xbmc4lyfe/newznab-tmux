<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\QueueType;
use App\Enums\RegistrationStatus;
use App\Enums\SignupError;
use App\Enums\UserRole;
use App\Models\User;
use App\Support\RustPipelineLibrary;
use App\View\Composers\AdminDataComposer;
use Closure;
use FFI;
use Illuminate\Contracts\View\Factory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\Engines\PhpEngine;
use Illuminate\View\View;
use Random\Engine\Mt19937;
use Random\Randomizer;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Wave 25.5 parity, caller wiring, C ABI safety and paired performance gate for
 * `SignupError`, `UserRole`, `QueueType`, `RegistrationStatus` and
 * `AdminDataComposer` (`rust/pipeline-ffi/src/search.rs`,
 * `signup_role_queue_enum_composer`).
 *
 * Protocol: seed 2505; 3 rounds x 41 randomized pairs x 200 iterations, 50 warmups
 * per side; output equality verified before timing. Samples are batch per-call means;
 * p99 uses nearest rank (max of 41), not a production request-tail estimate.
 * Adoption requires >= 1.5x on common mean/p99 and uncommon mean/p99 in every round.
 *
 * Recorded on Apple M5 arm64, PHP 8.5.12 NTS CLI (opcache/JIT disabled), rustc/cargo 1.99.1,
 * release opt-level=3/lto/codegen-units=1, host-default resource limits, base
 * 5871398b6dcd9dc834e704b0c8386b8991412806 plus this slice, 2026-10-24.
 * Worst-round PHP / Native mean and p99 speedups (all classes default to PHP):
 *
 * | Helper                                | Common mean / p99 | Uncommon mean / p99 |
 * |---------------------------------------|-------------------|---------------------|
 * | SignupError::message                  | 0.17x / 0.13x     | 0.16x / 0.18x       |
 * | SignupError::isError                  | 0.12x / 0.12x     | 0.12x / 0.12x       |
 * | SignupError::tryFromMessage           | 1.41x / 0.89x     | 1.53x / 1.14x       |
 * | UserRole::label                       | 0.09x / 0.08x     | 0.13x / 0.10x       |
 * | UserRole::hasElevatedPrivileges       | 0.28x / 0.26x     | 0.30x / 0.30x       |
 * | UserRole::tryFromLabel                | 0.45x / 0.38x     | 0.86x / 0.98x       |
 * | QueueType::label                      | 0.10x / 0.11x     | 0.12x / 0.15x       |
 * | QueueType::tryFromLabel               | 0.41x / 0.47x     | 0.60x / 0.56x       |
 * | RegistrationStatus::label             | 0.09x / 0.09x     | 0.12x / 0.12x       |
 * | RegistrationStatus::tryFromLabel      | 0.30x / 0.29x     | 0.59x / 0.52x       |
 * | AdminDataComposer::normalizeViewState | 0.07x / 0.06x     | 0.05x / 0.05x       |
 *
 * Each Native helper includes FFI call, marshalling, scratch/slot allocation and
 * result conversion. These sub-microsecond enum lookups are dominated by the FFI
 * boundary, so no helper meets the gate and `usesRustFfi() === false` is retained.
 * `tryFromMessage` comes closest (one PHP loop over seven cases vs one FFI call) but
 * still fails its common mean/p99 and uncommon p99 gates.
 * These are local helper measurements, not application/production throughput claims.
 * Raw 2,706 pairs, order, per-round latencies, source/library hashes and environment
 * metadata are in the benchmark's printed JSONL artifact:
 * /private/var/folders/gx/2s9j3tj918q0m2j3ns56pz7h0000gn/T/wave25-5-bench-71r3fltl3ktkekQhaHx
 *
 * Reproduce from this worktree:
 * CARGO_TARGET_DIR=/tmp/cargo-w25-5 cargo test --manifest-path rust/pipeline-ffi/Cargo.toml --lib
 * CARGO_TARGET_DIR=/tmp/cargo-w25-5 cargo build --manifest-path rust/pipeline-ffi/Cargo.toml --release
 * NNTMUX_PIPELINE_FFI_LIB=/tmp/cargo-w25-5/release/libnntmux_pipeline.dylib NNTMUX_SIGNUP_ROLE_QUEUE_ENUM_COMPOSER_BENCH=1 \
 *   php artisan test --compact tests/Unit/RustSignupRoleQueueEnumComposerParityTest.php
 */
class RustSignupRoleQueueEnumComposerParityTest extends TestCase
{
    /** @var list<class-string> */
    private const array CLASSES = [SignupError::class, UserRole::class, QueueType::class, RegistrationStatus::class, AdminDataComposer::class];

    private const int DECLINE = -2147483648;

    protected function tearDown(): void
    {
        foreach (self::CLASSES as $class) {
            $class::resetRustFfi();
        }
        parent::tearDown();
    }

    private static function libraryPath(): ?string
    {
        $path = getenv('NNTMUX_PIPELINE_FFI_LIB');

        return \is_string($path) && $path !== '' ? $path : null;
    }

    private function library(): FFI
    {
        $ffi = RustPipelineLibrary::resolveDomain('search', SignupError::FFI_CDEF."\n".AdminDataComposer::FFI_CDEF, self::libraryPath());
        self::assertInstanceOf(FFI::class, $ffi, 'Build the release library and set NNTMUX_PIPELINE_FFI_LIB; parity must exercise actual Rust exports.');
        foreach (self::CLASSES as $class) {
            $class::setFfiForTesting($ffi);
        }

        return $ffi;
    }

    private static function callFfi(FFI $ffi, string $symbol, mixed ...$args): mixed
    {
        return $ffi->$symbol(...$args);
    }

    public function test_defaults_and_toggles(): void
    {
        foreach (self::CLASSES as $class) {
            self::assertFalse($class::usesRustFfi(), $class.' must default to PHP');
            $class::setUseRustFfi(true);
            self::assertTrue($class::usesRustFfi());
            $class::setUseRustFfi(false);
            self::assertFalse($class::usesRustFfi());
            $class::setUseRustFfi(true);
            $class::resetRustFfi();
            self::assertFalse($class::usesRustFfi());
        }
        self::assertSame('Registration successful.', SignupError::SUCCESS->message());
        self::assertTrue(SignupError::BAD_EMAIL->isError());
        self::assertFalse(SignupError::BAD_EMAIL->isSuccess());
        self::assertSame('Moderator', UserRole::MODERATOR->label());
        self::assertTrue(UserRole::MODERATOR->hasElevatedPrivileges());
        self::assertSame('SABnzbd', QueueType::SABNZBD->label());
        self::assertSame('Invite', RegistrationStatus::Invite->label());
        self::assertSame(UserRole::ADMIN, UserRole::tryFromLabel('Admin'));
        self::assertNull(UserRole::tryFromLabel('admin'));
        self::assertSame(SignupError::BAD_INVITE_CODE, SignupError::tryFromMessage('Invalid or expired invite code.'));
    }

    public function test_enum_label_flag_and_resolution_parity(): void
    {
        $this->library();
        foreach ([false, true] as $native) {
            foreach (self::CLASSES as $class) {
                $class::setUseRustFfi($native);
            }
            foreach (SignupError::cases() as $case) {
                self::assertSame($case->messagePhp(), $case->messageNative());
                self::assertSame($case->messagePhp(), $case->message());
                self::assertSame($case->isErrorPhp(), $case->isErrorNative());
                self::assertSame($case->isErrorPhp(), $case->isError());
                self::assertSame($case->isSuccessPhp(), $case->isSuccessNative());
                self::assertSame($case->isSuccessPhp(), $case->isSuccess());
                self::assertSame($case, SignupError::tryFromMessageNative($case->messagePhp()));
                self::assertSame($case, SignupError::tryFromMessage($case->messagePhp()));
            }
            foreach (UserRole::cases() as $case) {
                self::assertSame($case->labelPhp(), $case->labelNative());
                self::assertSame($case->labelPhp(), $case->label());
                foreach (['isAdmin', 'isModerator', 'isDisabled', 'hasElevatedPrivileges'] as $method) {
                    $php = $method.'Php';
                    $nativeMethod = $method.'Native';
                    self::assertSame($case->$php(), $case->$nativeMethod(), $case->name.' '.$method);
                    self::assertSame($case->$php(), $case->$method());
                }
                self::assertSame($case, UserRole::tryFromLabelNative($case->labelPhp()));
            }
            foreach ([QueueType::class, RegistrationStatus::class] as $enum) {
                foreach ($enum::cases() as $case) {
                    self::assertSame($case->labelPhp(), $case->labelNative());
                    self::assertSame($case->labelPhp(), $case->label());
                    self::assertSame($case, $enum::tryFromLabelNative($case->labelPhp()));
                    self::assertSame($case, $enum::tryFromLabel($case->labelPhp()));
                }
            }
        }
        $misses = ['', ' ', 'user', 'ADMIN', 'Admin ', "Admin\0", "\xff", 'é', 'Sabnzbd', 'open', 'Registration successful', str_repeat('x', 4096), str_repeat('y', (1 << 20) + 1)];
        foreach ([...$misses, 'User', 'Admin', 'Disabled', 'Moderator', 'None', 'SABnzbd', 'NZBGet', 'Open', 'Invite', 'Closed', 'Registration successful.'] as $label) {
            self::assertSame(SignupError::tryFromMessagePhp($label), SignupError::tryFromMessageNative($label));
            self::assertSame(UserRole::tryFromLabelPhp($label), UserRole::tryFromLabelNative($label));
            self::assertSame(QueueType::tryFromLabelPhp($label), QueueType::tryFromLabelNative($label));
            self::assertSame(RegistrationStatus::tryFromLabelPhp($label), RegistrationStatus::tryFromLabelNative($label));
        }
    }

    public function test_composer_view_state_matrix_parity(): void
    {
        $this->library();
        $preferences = [null, '', '0', 'light', 'dark', 'blue', "\0\xff", 'é😀', str_repeat('t', 8192), 0, 1, false, true, 1.5, [], ['dark']];
        foreach ([false, true] as $loggedIn) {
            foreach ([false, true] as $isUser) {
                foreach ([false, true] as $isAdmin) {
                    foreach ([false, true] as $isMod) {
                        foreach ($preferences as $theme) {
                            foreach ($preferences as $color) {
                                $php = AdminDataComposer::normalizeViewStatePhp($loggedIn, $isUser, $isAdmin, $isMod, $theme, $color);
                                self::assertSame($php, AdminDataComposer::normalizeViewStateNative($loggedIn, $isUser, $isAdmin, $isMod, $theme, $color));
                                self::assertSame(['loggedin', 'isadmin', 'ismod', 'userTheme', 'userColorScheme'], array_keys($php));
                            }
                        }
                    }
                }
            }
        }
        self::assertSame(['loggedin' => true, 'isadmin' => false, 'ismod' => true, 'userTheme' => 'light', 'userColorScheme' => ''], AdminDataComposer::normalizeViewStateNative(true, true, false, true, null, ''));
        self::assertSame(['loggedin' => true, 'isadmin' => false, 'ismod' => false, 'userTheme' => 'light', 'userColorScheme' => 'blue'], AdminDataComposer::normalizeViewStateNative(true, false, true, true, 'dark', 'red'));
    }

    public function test_real_composer_caller_preserves_view_data(): void
    {
        $this->library();
        $user = new User;
        $user->setRawAttributes(['id' => 9, 'theme_preference' => 'dark', 'color_scheme' => null]);
        // Preloaded roles keep hasRole() off the database.
        $user->setRelation('roles', new Collection([(new Role)->forceFill(['id' => 4, 'name' => 'Moderator', 'guard_name' => 'web'])]));
        $results = [];
        foreach ([null, $user] as $actor) {
            foreach ([false, true] as $native) {
                AdminDataComposer::setUseRustFfi($native);
                Auth::forgetGuards();
                if ($actor !== null) {
                    Auth::setUser($actor);
                }
                $view = new View($this->app->make(Factory::class), new PhpEngine(new Filesystem), 'admin.fixture', '/dev/null');
                $this->app->make(AdminDataComposer::class)->compose($view);
                $captured = $view->getData();
                self::assertSame(['serverroot', 'site', 'siteLogoUrl', 'userdata', 'loggedin', 'isadmin', 'ismod', 'userTheme', 'userColorScheme'], array_keys($captured));
                self::assertSame($actor, $captured['userdata']);
                unset($captured['site']);
                $results[$actor === null ? 'guest' : 'user'][] = $captured;
            }
        }
        self::assertSame($results['guest'][0], $results['guest'][1]);
        self::assertSame($results['user'][0], $results['user'][1]);
        self::assertSame([false, false, false, 'light', 'blue'], [$results['guest'][0]['loggedin'], $results['guest'][0]['isadmin'], $results['guest'][0]['ismod'], $results['guest'][0]['userTheme'], $results['guest'][0]['userColorScheme']]);
        self::assertSame([true, false, true, 'dark', 'blue'], [$results['user'][0]['loggedin'], $results['user'][0]['isadmin'], $results['user'][0]['ismod'], $results['user'][0]['userTheme'], $results['user'][0]['userColorScheme']]);
    }

    public function test_unavailable_and_incompatible_ffi_fall_back_to_php(): void
    {
        foreach ([false, FFI::cdef('int abs(int);')] as $override) {
            foreach (self::CLASSES as $class) {
                $class::setUseRustFfi(true);
                $class::setFfiForTesting($override);
            }
            foreach (SignupError::cases() as $case) {
                self::assertSame($case->messagePhp(), $case->message());
                self::assertSame($case->isErrorPhp(), $case->isError());
                self::assertSame($case->isSuccessPhp(), $case->isSuccess());
                self::assertSame($case, SignupError::tryFromMessage($case->messagePhp()));
            }
            foreach (UserRole::cases() as $case) {
                self::assertSame($case->labelPhp(), $case->label());
                self::assertSame($case->hasElevatedPrivilegesPhp(), $case->hasElevatedPrivileges());
                self::assertSame($case->isDisabledPhp(), $case->isDisabled());
                self::assertSame($case, UserRole::tryFromLabel($case->labelPhp()));
            }
            self::assertSame('NZBGet', QueueType::NZBGET->label());
            self::assertNull(QueueType::tryFromLabel('nzbget'));
            self::assertSame('Closed', RegistrationStatus::Closed->label());
            self::assertSame(RegistrationStatus::Open, RegistrationStatus::tryFromLabel('Open'));
            self::assertSame(AdminDataComposer::normalizeViewStatePhp(true, true, true, false, 'dark', null), AdminDataComposer::normalizeViewState(true, true, true, false, 'dark', null));
        }
    }

    public function test_randomized_binary_parity(): void
    {
        $this->library();
        $random = new Randomizer(new Mt19937(2505));
        $bytes = static fn (int $max): string => ($length = $random->getInt(0, $max)) === 0 ? '' : $random->getBytes($length);
        $labels = ['User', 'Admin', 'Disabled', 'Moderator', 'None', 'SABnzbd', 'NZBGet', 'Open', 'Invite', 'Closed', 'Registration successful.'];
        for ($i = 0; $i < 400; $i++) {
            $value = $random->getInt(0, 3) === 0 ? $labels[$random->getInt(0, \count($labels) - 1)] : $bytes(48);
            self::assertSame(SignupError::tryFromMessagePhp($value), SignupError::tryFromMessageNative($value));
            self::assertSame(UserRole::tryFromLabelPhp($value), UserRole::tryFromLabelNative($value));
            self::assertSame(QueueType::tryFromLabelPhp($value), QueueType::tryFromLabelNative($value));
            self::assertSame(RegistrationStatus::tryFromLabelPhp($value), RegistrationStatus::tryFromLabelNative($value));
            $theme = $random->getInt(0, 4) === 0 ? null : $bytes(64);
            $color = $random->getInt(0, 4) === 0 ? null : $bytes(64);
            $flags = $random->getInt(0, 15);
            $args = [(bool) ($flags & 1), (bool) ($flags & 2), (bool) ($flags & 4), (bool) ($flags & 8), $theme, $color];
            self::assertSame(AdminDataComposer::normalizeViewStatePhp(...$args), AdminDataComposer::normalizeViewStateNative(...$args));
        }
    }

    public function test_c_abi_buffers_nulls_overflow_alignment_and_atomicity(): void
    {
        $ffi = $this->library();
        $buffer = $ffi->new('char[256]');
        // Probe, short buffer (atomic), exact buffer.
        foreach ([[0, -5, 'Email address is already in use.'], [1, 4, 'Moderator'], [2, 1, 'SABnzbd'], [3, 2, 'Closed']] as [$kind, $value, $expected]) {
            $length = \strlen($expected);
            self::assertSame(-$length, self::callFfi($ffi, 'nntmux_signup_role_queue_label', $kind, $value, null, 0));
            FFI::memset($buffer, 0x5A, 256);
            self::assertSame(-$length, self::callFfi($ffi, 'nntmux_signup_role_queue_label', $kind, $value, $buffer, $length - 1));
            self::assertSame(str_repeat('Z', 256), FFI::string($buffer, 256));
            self::assertSame($length, self::callFfi($ffi, 'nntmux_signup_role_queue_label', $kind, $value, $buffer, $length));
            self::assertSame($expected, FFI::string($buffer, $length));
            self::assertSame('Z', $buffer[$length]);
            self::assertSame(0, self::callFfi($ffi, 'nntmux_signup_role_queue_label', $kind, 77, $buffer, 256));
            self::assertSame(0, self::callFfi($ffi, 'nntmux_signup_role_queue_flags', $kind, PHP_INT_MIN));
        }
        self::assertSame(self::DECLINE, self::callFfi($ffi, 'nntmux_signup_role_queue_label', 4, 1, $buffer, 256));
        self::assertSame(self::DECLINE, self::callFfi($ffi, 'nntmux_signup_role_queue_flags', 4, 1));
        $overflow = $ffi->cast('char *', -8);
        foreach ([[null, 16], [$overflow, 32], [$buffer, -1]] as [$out, $cap]) {
            self::assertSame(self::DECLINE, self::callFfi($ffi, 'nntmux_signup_role_queue_label', 1, 2, $out, $cap));
        }
        $words = $ffi->new('int64_t[4]');
        FFI::memset($words, 0x5A, 32);
        $bytes = $ffi->cast('char *', FFI::addr($words[0]));
        $unaligned = $ffi->cast('int64_t *', FFI::addr($bytes[1]));
        foreach ([null, $unaligned, $ffi->cast('int64_t *', -8)] as $out) {
            self::assertSame(self::DECLINE, self::callFfi($ffi, 'nntmux_signup_role_queue_resolve_label', 1, 'User', 4, $out));
        }
        foreach ([[null, 1], [$overflow, 32], ['x', -1], ['x', (1 << 20) + 1]] as [$input, $length]) {
            self::assertSame(self::DECLINE, self::callFfi($ffi, 'nntmux_signup_role_queue_resolve_label', 1, $input, $length, $words));
        }
        self::assertSame(self::DECLINE, self::callFfi($ffi, 'nntmux_signup_role_queue_resolve_label', 9, 'User', 4, $words));
        self::assertSame(0, self::callFfi($ffi, 'nntmux_signup_role_queue_resolve_label', 1, 'user', 4, $words));
        self::assertSame(str_repeat('Z', 32), FFI::string($bytes, 32));
        self::assertSame(1, self::callFfi($ffi, 'nntmux_signup_role_queue_resolve_label', 1, 'Moderator', 9, $words));
        self::assertSame(4, $words[0]);
        self::assertSame(0, self::callFfi($ffi, 'nntmux_signup_role_queue_resolve_label', 2, null, 0, $words));

        $state = $ffi->new('uint32_t[4]');
        $lens = $ffi->new('size_t[3]');
        $view = static fn (array $flags, mixed $theme, int $themeLen, mixed $color, int $colorLen, mixed $outFlags, mixed $outLens, mixed $out, int $cap): mixed => self::callFfi($ffi, 'nntmux_admin_composer_view_state', $flags[0], $flags[1], $flags[2], $flags[3], $flags[4], $theme, $themeLen, $flags[5], $color, $colorLen, $outFlags, $outLens, $out, $cap);
        $reset = static function () use ($buffer, $state, $lens): void {
            FFI::memset($buffer, 0x5A, 256);
            FFI::memset($state, 0x5A, 16);
            FFI::memset($lens, 0x5A, FFI::sizeof($lens));
        };
        $snapshot = static fn (): string => FFI::string($buffer, 256).FFI::string($ffi->cast('char *', FFI::addr($state[0])), 16).FFI::string($ffi->cast('char *', FFI::addr($lens[0])), FFI::sizeof($lens));
        $reset();
        $pristine = $snapshot();
        self::assertSame(-8, $view([1, 1, 0, 1, 1, 0], 'dark', 4, '', 0, $state, $lens, null, 0));
        self::assertSame(-8, $view([1, 1, 0, 1, 1, 0], 'dark', 4, '', 0, $state, $lens, $buffer, 7));
        self::assertSame($pristine, $snapshot());
        self::assertSame(8, $view([1, 1, 0, 1, 1, 0], 'dark', 4, '', 0, $state, $lens, $buffer, 8));
        self::assertSame('darkblue', FFI::string($buffer, 8));
        self::assertSame([1, 0, 1, 4, 4], [$state[0], $state[1], $state[2], $lens[0], $lens[1]]);
        $reset();
        $stateBytes = $ffi->cast('char *', FFI::addr($state[0]));
        $lensBytes = $ffi->cast('char *', FFI::addr($lens[0]));
        $declines = [
            [[2, 1, 0, 0, 1, 1], 'd', 1, 'c', 1, $state, $lens, $buffer, 256],
            [[1, 1, 0, 0, 1, 2], 'd', 1, 'c', 1, $state, $lens, $buffer, 256],
            [[1, 1, 0, 0, 1, 1], null, 1, 'c', 1, $state, $lens, $buffer, 256],
            [[1, 1, 0, 0, 1, 1], 'd', 1, $overflow, 32, $state, $lens, $buffer, 256],
            [[1, 1, 0, 0, 1, 1], 'd', -1, 'c', 1, $state, $lens, $buffer, 256],
            [[1, 1, 0, 0, 1, 1], 'd', 1, 'c', 1, null, $lens, $buffer, 256],
            [[1, 1, 0, 0, 1, 1], 'd', 1, 'c', 1, $ffi->cast('uint32_t *', FFI::addr($stateBytes[1])), $lens, $buffer, 256],
            [[1, 1, 0, 0, 1, 1], 'd', 1, 'c', 1, $state, $ffi->cast('size_t *', FFI::addr($lensBytes[1])), $buffer, 256],
            [[1, 1, 0, 0, 1, 1], 'd', 1, 'c', 1, $state, $ffi->cast('size_t *', -8), $buffer, 256],
            [[1, 1, 0, 0, 1, 1], 'd', 1, 'c', 1, $state, $lens, null, 16],
            [[1, 1, 0, 0, 1, 1], 'd', 1, 'c', 1, $state, $lens, $overflow, 32],
            [[1, 1, 0, 0, 1, 1], 'd', 1, 'c', 1, $state, $lens, $buffer, -1],
        ];
        foreach ($declines as $index => $arguments) {
            self::assertSame(self::DECLINE, $view(...$arguments), 'view-state decline case '.$index);
        }
        self::assertSame($pristine, $snapshot());
    }

    public function test_paired_randomized_benchmark(): void
    {
        if (getenv('NNTMUX_SIGNUP_ROLE_QUEUE_ENUM_COMPOSER_BENCH') !== '1') {
            $this->markTestSkipped('Set NNTMUX_SIGNUP_ROLE_QUEUE_ENUM_COMPOSER_BENCH=1 for the release-library benchmark.');
        }
        $this->library();
        $long = str_repeat('x', 4096);
        $longTheme = str_repeat('t', 4096);
        /** @var array<string, array{class-string, array{Closure, Closure}, array{Closure, Closure}}> $workloads */
        $workloads = [
            'SignupError::message' => [SignupError::class, [static fn () => SignupError::SUCCESS->messagePhp(), static fn () => SignupError::SUCCESS->messageNative()], [static fn () => SignupError::BAD_INVITE_CODE->messagePhp(), static fn () => SignupError::BAD_INVITE_CODE->messageNative()]],
            'SignupError::isError' => [SignupError::class, [static fn () => SignupError::SUCCESS->isErrorPhp(), static fn () => SignupError::SUCCESS->isErrorNative()], [static fn () => SignupError::EMAIL_IN_USE->isErrorPhp(), static fn () => SignupError::EMAIL_IN_USE->isErrorNative()]],
            'SignupError::tryFromMessage' => [SignupError::class, [static fn () => SignupError::tryFromMessagePhp('Registration successful.'), static fn () => SignupError::tryFromMessageNative('Registration successful.')], [static fn () => SignupError::tryFromMessagePhp($long), static fn () => SignupError::tryFromMessageNative($long)]],
            'UserRole::label' => [UserRole::class, [static fn () => UserRole::USER->labelPhp(), static fn () => UserRole::USER->labelNative()], [static fn () => UserRole::MODERATOR->labelPhp(), static fn () => UserRole::MODERATOR->labelNative()]],
            'UserRole::hasElevatedPrivileges' => [UserRole::class, [static fn () => UserRole::USER->hasElevatedPrivilegesPhp(), static fn () => UserRole::USER->hasElevatedPrivilegesNative()], [static fn () => UserRole::MODERATOR->hasElevatedPrivilegesPhp(), static fn () => UserRole::MODERATOR->hasElevatedPrivilegesNative()]],
            'UserRole::tryFromLabel' => [UserRole::class, [static fn () => UserRole::tryFromLabelPhp('Admin'), static fn () => UserRole::tryFromLabelNative('Admin')], [static fn () => UserRole::tryFromLabelPhp($long), static fn () => UserRole::tryFromLabelNative($long)]],
            'QueueType::label' => [QueueType::class, [static fn () => QueueType::NONE->labelPhp(), static fn () => QueueType::NONE->labelNative()], [static fn () => QueueType::NZBGET->labelPhp(), static fn () => QueueType::NZBGET->labelNative()]],
            'QueueType::tryFromLabel' => [QueueType::class, [static fn () => QueueType::tryFromLabelPhp('SABnzbd'), static fn () => QueueType::tryFromLabelNative('SABnzbd')], [static fn () => QueueType::tryFromLabelPhp($long), static fn () => QueueType::tryFromLabelNative($long)]],
            'RegistrationStatus::label' => [RegistrationStatus::class, [static fn () => RegistrationStatus::Open->labelPhp(), static fn () => RegistrationStatus::Open->labelNative()], [static fn () => RegistrationStatus::Closed->labelPhp(), static fn () => RegistrationStatus::Closed->labelNative()]],
            'RegistrationStatus::tryFromLabel' => [RegistrationStatus::class, [static fn () => RegistrationStatus::tryFromLabelPhp('Open'), static fn () => RegistrationStatus::tryFromLabelNative('Open')], [static fn () => RegistrationStatus::tryFromLabelPhp($long), static fn () => RegistrationStatus::tryFromLabelNative($long)]],
            'AdminDataComposer::normalizeViewState' => [AdminDataComposer::class, [static fn () => AdminDataComposer::normalizeViewStatePhp(true, true, true, false, 'dark', null), static fn () => AdminDataComposer::normalizeViewStateNative(true, true, true, false, 'dark', null)], [static fn () => AdminDataComposer::normalizeViewStatePhp(true, true, false, true, $longTheme, $longTheme), static fn () => AdminDataComposer::normalizeViewStateNative(true, true, false, true, $longTheme, $longTheme)]],
        ];
        $sources = [];
        foreach (['rust/pipeline-ffi/src/search.rs', 'app/Enums/SignupError.php', 'app/Enums/UserRole.php', 'app/Enums/QueueType.php', 'app/Enums/RegistrationStatus.php', 'app/View/Composers/AdminDataComposer.php', 'tests/Unit/RustSignupRoleQueueEnumComposerParityTest.php', 'composer.lock'] as $file) {
            $sources[$file] = hash_file('sha256', base_path($file));
        }
        $library = self::libraryPath();
        $sources['library:'.($library ?? 'default')] = $library !== null && is_file($library) ? hash_file('sha256', $library) : null;
        $metadata = ['time' => gmdate('c'), 'head' => trim((string) shell_exec('git rev-parse HEAD')), 'php' => PHP_VERSION, 'platform' => php_uname(), 'cpu' => trim((string) shell_exec(PHP_OS_FAMILY === 'Darwin' ? 'sysctl -n machdep.cpu.brand_string' : 'uname -m')), 'rustc' => trim((string) shell_exec('rustc -V')), 'cargo' => trim((string) shell_exec('cargo -V')), 'profile' => 'release opt-level=3 lto=true codegen-units=1', 'resource_limits' => 'host defaults, one process, no I/O in timed helpers', 'php_jit' => ini_get('opcache.jit'), 'php_opcache_cli' => ini_get('opcache.enable_cli'), 'ffi_enable' => ini_get('ffi.enable'), 'sources' => $sources, 'protocol' => ['seed' => 2505, 'rounds' => 3, 'samples' => 41, 'iterations' => 200, 'warmup' => 50, 'p99' => 'nearest rank; batch per-call means', 'speedup' => 'PHP latency / Native latency'], 'uncertainty' => '41 batch samples per round cannot establish production p99; conservative worst-round gate; no production throughput claim', 'failures' => []];
        $artifact = tempnam(sys_get_temp_dir(), 'wave25-5-bench-');
        self::assertNotFalse($artifact);
        self::assertNotFalse(file_put_contents($artifact, json_encode(['metadata' => $metadata], JSON_THROW_ON_ERROR).PHP_EOL));
        $random = new Randomizer(new Mt19937(2505));
        $rows = [];
        foreach ($workloads as $method => [$class, $commonCalls, $uncommonCalls]) {
            $worst = ['common_mean' => INF, 'common_p99' => INF, 'uncommon_mean' => INF, 'uncommon_p99' => INF];
            foreach (range(1, 3) as $round) {
                foreach (['common' => $commonCalls, 'uncommon' => $uncommonCalls] as $kind => [$phpCall, $nativeCall]) {
                    $calls = ['php' => $phpCall, 'native' => $nativeCall];
                    self::assertSame($calls['php'](), $calls['native'](), $method.' benchmark parity');
                    for ($warmup = 0; $warmup < 50; $warmup++) {
                        $calls['native']();
                        $calls['php']();
                    }
                    $times = ['native' => [], 'php' => []];
                    $orders = [];
                    for ($sample = 0; $sample < 41; $sample++) {
                        $order = $random->getInt(0, 1) === 0 ? ['native', 'php'] : ['php', 'native'];
                        $orders[] = $order;
                        foreach ($order as $side) {
                            $call = $calls[$side];
                            $start = hrtime(true);
                            for ($iteration = 0; $iteration < 200; $iteration++) {
                                $call();
                            }
                            $times[$side][] = (hrtime(true) - $start) / 200;
                        }
                    }
                    $nativeTimes = $times['native'];
                    $phpTimes = $times['php'];
                    sort($nativeTimes);
                    sort($phpTimes);
                    $mean = (array_sum($phpTimes) / 41) / (array_sum($nativeTimes) / 41);
                    $p99 = $phpTimes[40] / $nativeTimes[40];
                    $worst[$kind.'_mean'] = min($worst[$kind.'_mean'], $mean);
                    $worst[$kind.'_p99'] = min($worst[$kind.'_p99'], $p99);
                    $result = ['method' => $method, 'workload' => $kind, 'round' => $round, 'native_mean_ns' => array_sum($nativeTimes) / 41, 'php_mean_ns' => array_sum($phpTimes) / 41, 'native_p99_ns' => $nativeTimes[40], 'php_p99_ns' => $phpTimes[40], 'mean_speedup' => $mean, 'p99_speedup' => $p99, 'raw_paired_ns' => $times, 'orders' => $orders];
                    self::assertNotFalse(file_put_contents($artifact, json_encode($result, JSON_THROW_ON_ERROR).PHP_EOL, FILE_APPEND));
                }
            }
            $qualified = min($worst) >= 1.5;
            $rows[] = sprintf(' * | %-38s | %.2fx / %.2fx | %.2fx / %.2fx | %s', $method, $worst['common_mean'], $worst['common_p99'], $worst['uncommon_mean'], $worst['uncommon_p99'], $qualified ? 'qualifies' : 'PHP default');
            if ($class::usesRustFfi()) {
                self::assertTrue($qualified, $method.' cannot default to Rust below its performance gate');
            }
        }
        self::assertNotFalse(file_put_contents($artifact, json_encode(['summary' => $rows], JSON_THROW_ON_ERROR).PHP_EOL, FILE_APPEND));
        fwrite(STDERR, PHP_EOL.implode(PHP_EOL, $rows).PHP_EOL.'Raw benchmark evidence: '.$artifact.PHP_EOL);
    }
}
