<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Http\Middleware\TrustedDevice2FAMiddleware;
use App\Models\PasswordSecurity;
use App\Models\User;
use App\Support\Auth\ThrottlesLogins;
use App\Support\Forum\CategoryTreeBuilder;
use App\Support\Google2FAAuthenticator;
use App\Support\PermissionSyncHelper;
use App\Support\RustPipelineLibrary;
use Closure;
use FFI;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA as Google2FAService;
use PragmaRX\Google2FALaravel\Exceptions\InvalidSecretKey;
use ReflectionMethod;
use Spatie\Permission\Models\Role;
use stdClass;
use TeamTeaTime\Forum\Models\Category;
use Tests\TestCase;
use Throwable;

/**
 * Parity, C-ABI contract, fallback, and paired benchmark verification for Wave 23 Slice 23.4:
 * Google2FAAuthenticator, PermissionSyncHelper, CategoryTreeBuilder, ThrottlesLogins, and
 * TrustedDevice2FAMiddleware.
 *
 * Benchmarks run only with NNTMUX_SECURITY_FORUM_SUPPORT_BENCH=1:
 *   cargo build --manifest-path rust/pipeline-ffi/Cargo.toml --release
 *   cp rust/pipeline-ffi/target/release/libnntmux_pipeline.dylib storage/app/nntmux-native/
 *   NNTMUX_SECURITY_FORUM_SUPPORT_BENCH=1 php artisan test --compact tests/Unit/RustSecurityForumSupportParityTest.php
 *
 * Paired randomized benchmark evidence (3 repetitions x 41 paired samples x 200 inner loops,
 * 50 warmup calls per side, worst-repetition PHP latency / Rust latency, FFI call overhead
 * included):
 *
 * | Method                                                   | Common (Mean / P99) | Uncommon (Mean / P99) | >= 1.5x | Default     |
 * |----------------------------------------------------------|---------------------|-----------------------|---------|-------------|
 * | Google2FAAuthenticator::validateSecretAndNormalizeOtp    | 0.29x / 0.28x       | 2.40x / 1.35x         | No      | PHP (false) |
 * | PermissionSyncHelper::computeUserPermissionDiff          | 0.55x / 0.53x       | 0.44x / 0.31x         | No      | PHP (false) |
 * | PermissionSyncHelper::computeRolePermissionDiff          | 0.53x / 0.53x       | 0.79x / 0.62x         | No      | PHP (false) |
 * | CategoryTreeBuilder::resolveRootParentIdFromNodes        | 0.35x / 0.38x       | 0.72x / 0.74x         | No      | PHP (false) |
 * | ThrottlesLogins::buildThrottleKey                        | 0.79x / 0.76x       | 2.10x / 1.68x         | No      | PHP (false) |
 * | ThrottlesLogins::computeLockoutMinutes                   | 0.40x / 0.26x       | 0.43x / 0.33x         | No      | PHP (false) |
 * | TrustedDevice2FAMiddleware::serializeTrustedDeviceCookie | 0.37x / 0.36x       | 0.64x / 0.66x         | No      | PHP (false) |
 *
 * Measured on Apple M5 (arm64), PHP 8.5.12 (NTS), release build of 775ba6cf8 plus this change.
 * Every helper misses the gate on its common workload (the FFI call and argument marshalling
 * cost more than the PHP work), so every class keeps PHP as its production default.
 */
class RustSecurityForumSupportParityTest extends TestCase
{
    /** @var list<class-string> */
    private const array TOGGLED_CLASSES = [
        Google2FAAuthenticator::class,
        PermissionSyncHelper::class,
        CategoryTreeBuilder::class,
        SecurityForumSupportThrottleFixture::class,
        TrustedDevice2FAMiddleware::class,
    ];

    private const string SECRET = 'JBSWY3DPEHPK3PXP';

    protected function setUp(): void
    {
        parent::setUp();
        $this->resetFfiState();
        if (RustPipelineLibrary::resolveDomain('search', CategoryTreeBuilder::FFI_CDEF) === null) {
            $this->markTestSkipped('Rust pipeline library is not built; run cargo build --release.');
        }
    }

    protected function tearDown(): void
    {
        $this->resetFfiState();
        parent::tearDown();
    }

    private function resetFfiState(): void
    {
        foreach (self::TOGGLED_CLASSES as $class) {
            $class::resetRustFfi();
            $class::setFfiForTesting(null);
        }
    }

    /**
     * @param  class-string  $class
     */
    private static function toggle(string $class, bool $use): void
    {
        $use ? $class::enableRustFfi() : $class::disableRustFfi();
    }

    /**
     * @param  class-string  $class
     * @return array{0: mixed, 1: mixed}
     */
    private static function bothWays(string $class, Closure $fn): array
    {
        $class::enableRustFfi();
        $rust = self::outcome($fn);
        $class::disableRustFfi();
        $php = self::outcome($fn);

        return [$rust, $php];
    }

    /**
     * @return array{ok: mixed}|array{error: class-string, message: string}
     */
    private static function outcome(Closure $fn): array
    {
        try {
            return ['ok' => $fn()];
        } catch (Throwable $e) {
            return ['error' => $e::class, 'message' => $e->getMessage()];
        }
    }

    private static function randomBytes(int $maxLen, string $alphabet = ''): string
    {
        $len = mt_rand(0, $maxLen);
        $out = '';
        for ($i = 0; $i < $len; $i++) {
            $out .= $alphabet === '' ? \chr(mt_rand(0, 255)) : $alphabet[mt_rand(0, \strlen($alphabet) - 1)];
        }

        return $out;
    }

    public function test_production_defaults_follow_benchmark_gate(): void
    {
        foreach (self::TOGGLED_CLASSES as $class) {
            $this->assertFalse(
                $class::usesRustFfi(),
                "{$class} must keep PHP as default until its Rust path passes the 1.5x gate across common and uncommon mean/p99"
            );
        }
    }

    public function test_toggle_api(): void
    {
        foreach (self::TOGGLED_CLASSES as $class) {
            $class::enableRustFfi();
            $this->assertTrue($class::usesRustFfi());
            $class::disableRustFfi();
            $this->assertFalse($class::usesRustFfi());
            $class::enableRustFfi();
            $class::resetRustFfi();
            $this->assertFalse($class::usesRustFfi());
        }
    }

    // ---------------------------------------------------------------------
    // 1. Google2FAAuthenticator
    // ---------------------------------------------------------------------

    public function test_secret_validation_and_otp_normalization_parity(): void
    {
        $secrets = [self::SECRET, '', '0', '00', ' ', '0.0', "\0", 'a', null, 0, 1, 0.0, 1.5, false, true, [], ['x'], new stdClass];
        $otps = [
            null, '', '0', '123456', ' 123 456 ', "1\t2\n3\r4\0 5\x0B6", "\x0C1", "12\xC2\xA034", 'abc def', "\xff \xfe",
            str_repeat(' 9', 300), 123456, 0, -7, 1.5, 1e25, true, false, [], ['1'], new stdClass,
        ];
        mt_srand(2304);
        for ($i = 0; $i < 300; $i++) {
            $otps[] = self::randomBytes(24, " \t\n\r\0\x0B\x0C0123456789ab\xC3\xA9");
        }

        foreach ($secrets as $secret) {
            foreach ($otps as $otp) {
                [$rust, $php] = self::bothWays(
                    Google2FAAuthenticator::class,
                    static fn () => Google2FAAuthenticator::validateSecretAndNormalizeOtp($secret, $otp)
                );
                $this->assertSame($php, $rust, 'secret '.var_export($secret, true).' otp '.json_encode(\is_string($otp) ? bin2hex($otp) : $otp));
            }
        }

        $this->assertSame('123456', Google2FAAuthenticator::validateSecretAndNormalizeOtpPhp(self::SECRET, " 123\t456\n"));
        $this->assertSame('', Google2FAAuthenticator::validateSecretAndNormalizeOtpPhp(self::SECRET, ['123456']));
        $this->assertSame('123456', Google2FAAuthenticator::validateSecretAndNormalizeOtpNative(self::SECRET, 123456));
        $this->expectException(InvalidSecretKey::class);
        $this->expectExceptionMessage('Secret key cannot be empty.');
        Google2FAAuthenticator::validateSecretAndNormalizeOtpNative('0', '123456');
    }

    public function test_authenticator_verifies_normalized_otp_across_toggles(): void
    {
        $code = (new Google2FAService)->getCurrentOtp(self::SECRET);
        $spaced = ' '.substr($code, 0, 3).' '.substr($code, 3)."\n";

        foreach ([false, true] as $use) {
            self::toggle(Google2FAAuthenticator::class, $use);
            foreach ([[$code, true], [$spaced, true], ['000000x', false], [['x'], false]] as [$otp, $expected]) {
                $this->assertSame($expected, (bool) $this->verifyWithSecret(self::SECRET, $otp), 'rust='.var_export($use, true));
            }
            foreach (['', '0', null] as $secret) {
                $this->assertSame(
                    ['error' => InvalidSecretKey::class, 'message' => 'Secret key cannot be empty.'],
                    self::outcome(fn () => $this->verifyWithSecret($secret, $code))
                );
            }
        }
    }

    private function verifyWithSecret(mixed $secret, mixed $otp): mixed
    {
        $security = new PasswordSecurity;
        $security->setRawAttributes(['google2fa_secret' => $secret, 'google2fa_enable' => 1]);
        $user = new User;
        $user->setRawAttributes(['id' => 1]);
        $user->setRelation('passwordSecurity', $security);
        auth()->setUser($user);

        $request = Request::create('/2fa', 'POST');
        $request->setLaravelSession(app('session')->driver('array'));
        $authenticator = new Google2FAAuthenticator($request);
        $authenticator->boot($request);

        return Closure::bind(
            fn (mixed $password): mixed => $this->verifyAndStoreOneTimePassword($password),
            $authenticator,
            Google2FAAuthenticator::class
        )($otp);
    }

    // ---------------------------------------------------------------------
    // 2. PermissionSyncHelper
    // ---------------------------------------------------------------------

    private const array VIEW_INPUTS = [
        'viewconsole' => 'view console', 'viewmovies' => 'view movies', 'viewaudio' => 'view audio', 'viewpc' => 'view pc',
        'viewtv' => 'view tv', 'viewadult' => 'view adult', 'viewbooks' => 'view books', 'viewother' => 'view other',
    ];

    private const array ROLE_INPUTS = [
        'canpreview' => 'preview', 'hideads' => 'hideads', 'editrelease' => 'edit release',
    ] + self::VIEW_INPUTS;

    /**
     * @param  list<string>  $from
     * @return list<string>
     */
    private static function randomSubset(array $from): array
    {
        $subset = array_values(array_filter($from, static fn (): bool => mt_rand(0, 1) === 1));
        shuffle($subset);

        return $subset;
    }

    public function test_user_permission_diff_parity_with_original_loop(): void
    {
        mt_srand(2314);
        $inputs = array_keys(self::VIEW_INPUTS);
        $permissions = array_values(self::VIEW_INPUTS);
        $extras = ['preview', 'hideads', 'unknown', 'view Console', ''];
        for ($i = 0; $i < 2000; $i++) {
            $present = self::randomSubset([...$inputs, 'canpreview', 'viewConsole']);
            $direct = self::randomSubset([...$permissions, ...$extras]);
            $effective = $i % 3 === 0 ? array_values(array_unique([...$direct, ...self::randomSubset($permissions)])) : self::randomSubset([...$permissions, ...$extras]);

            $expected = ['grant' => [], 'revoke' => []];
            foreach (self::VIEW_INPUTS as $inputKey => $permission) {
                if (\in_array($inputKey, $present, true)) {
                    if (! \in_array($permission, $direct, true)) {
                        $expected['grant'][] = $permission;
                    }
                } elseif (\in_array($permission, $effective, true)) {
                    $expected['revoke'][] = $permission;
                }
            }

            $this->assertSame($expected, PermissionSyncHelper::computeUserPermissionDiffPhp($present, $direct, $effective));
            $this->assertSame($expected, PermissionSyncHelper::computeUserPermissionDiffNative($present, $direct, $effective));
        }

        $this->assertSame(
            ['grant' => [], 'revoke' => []],
            PermissionSyncHelper::computeUserPermissionDiffNative([1, null, true], [0, 'view console '], [1.5])
        );
    }

    public function test_role_permission_diff_parity_with_original_loops(): void
    {
        mt_srand(2324);
        $permissions = array_values(self::ROLE_INPUTS);
        $choices = [0, 1, 2, -1, PHP_INT_MAX, PHP_INT_MIN];
        for ($i = 0; $i < 2000; $i++) {
            $values = [];
            foreach (array_keys(self::ROLE_INPUTS) as $inputKey) {
                if (mt_rand(0, 9) > 0) {
                    $values[$inputKey] = $choices[mt_rand(0, \count($choices) - 1)];
                }
            }
            $current = self::randomSubset([...$permissions, 'view Console', 'other']);
            $revokeZero = $i % 2 === 0;

            $expected = ['grant' => [], 'revoke' => []];
            foreach (self::ROLE_INPUTS as $inputKey => $permission) {
                $value = $values[$inputKey] ?? 0;
                $held = \in_array($permission, $current, true);
                if ($value === 1 && ! $held) {
                    $expected['grant'][] = $permission;
                } elseif ($revokeZero && $value === 0 && $held) {
                    $expected['revoke'][] = $permission;
                }
            }

            $this->assertSame($expected, PermissionSyncHelper::computeRolePermissionDiffPhp($values, $current, $revokeZero));
            $this->assertSame($expected, PermissionSyncHelper::computeRolePermissionDiffNative($values, $current, $revokeZero));
        }
    }

    public function test_role_permission_callers_match_original_behaviour_across_toggles(): void
    {
        mt_srand(2334);
        $choices = ['0', '1', '2', '', 'on', null, '01', ' 1'];
        for ($i = 0; $i < 200; $i++) {
            $input = [];
            foreach (array_keys(self::ROLE_INPUTS) as $inputKey) {
                if (mt_rand(0, 4) > 0) {
                    $input[$inputKey] = $choices[mt_rand(0, \count($choices) - 1)];
                }
            }
            $held = self::randomSubset(array_values(self::ROLE_INPUTS));
            $request = Request::create('/admin/role', 'POST', $input);

            $expectedSync = [];
            $expectedGrant = [];
            foreach (self::ROLE_INPUTS as $inputKey => $permission) {
                $value = (int) $request->input($inputKey);
                if ($value === 1) {
                    $expectedGrant[] = ['give', $permission];
                }
                if ($value === 1 && ! \in_array($permission, $held, true)) {
                    $expectedSync[] = ['give', $permission];
                } elseif ($value === 0 && \in_array($permission, $held, true)) {
                    $expectedSync[] = ['revoke', $permission];
                }
            }
            usort($expectedSync, static fn (array $a, array $b): int => $a[0] <=> $b[0]);

            foreach ([false, true] as $use) {
                self::toggle(PermissionSyncHelper::class, $use);
                $calls = [];
                PermissionSyncHelper::syncRolePermissions($this->recordingRole($held, $calls), $request);
                usort($calls, static fn (array $a, array $b): int => $a[0] <=> $b[0]);
                $this->assertSame($expectedSync, $calls);

                $calls = [];
                PermissionSyncHelper::grantRolePermissions($this->recordingRole($held, $calls), $request);
                $this->assertSame($expectedGrant, $calls);
            }
        }
    }

    /**
     * @param  list<string>  $held
     * @param  list<array{0: string, 1: string}>  $calls
     */
    private function recordingRole(array $held, array &$calls): Role
    {
        $role = $this->getMockBuilder(Role::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['hasPermissionTo', 'givePermissionTo', 'revokePermissionTo'])
            ->getMock();
        $role->method('hasPermissionTo')->willReturnCallback(static fn ($permission): bool => \in_array($permission, $held, true));
        $role->method('givePermissionTo')->willReturnCallback(static function (...$permissions) use (&$calls, $role) {
            $calls[] = ['give', (string) $permissions[0]];

            return $role;
        });
        $role->method('revokePermissionTo')->willReturnCallback(static function ($permission) use (&$calls, $role) {
            $calls[] = ['revoke', (string) $permission];

            return $role;
        });

        return $role;
    }

    // ---------------------------------------------------------------------
    // 3. CategoryTreeBuilder
    // ---------------------------------------------------------------------

    public function test_root_parent_id_parity(): void
    {
        $corpus = [
            [],
            [['_lft' => 5, 'parent_id' => 1]],
            [['_lft' => 5, 'parent_id' => 1], ['_lft' => 3, 'parent_id' => 2], ['_lft' => 3, 'parent_id' => 3]],
            [['_lft' => 5, 'parent_id' => 1], ['_lft' => null, 'parent_id' => 2], ['_lft' => 3, 'parent_id' => 'x']],
            [['_lft' => null, 'parent_id' => 1], ['_lft' => null, 'parent_id' => 2], ['_lft' => 4, 'parent_id' => null], ['_lft' => null, 'parent_id' => 9]],
            [['_lft' => PHP_INT_MIN, 'parent_id' => 1], ['_lft' => PHP_INT_MAX, 'parent_id' => 2]],
            [['_lft' => '10', 'parent_id' => 1], ['_lft' => '9', 'parent_id' => 2], ['_lft' => 2, 'parent_id' => 3]],
            [['_lft' => 2.5, 'parent_id' => 1], ['_lft' => 2, 'parent_id' => 2]],
            [7 => ['_lft' => 9, 'parent_id' => 1], 'k' => ['_lft' => 1, 'parent_id' => '7']],
        ];
        mt_srand(2344);
        for ($i = 0; $i < 1000; $i++) {
            $nodes = [];
            $count = mt_rand(0, 40);
            for ($n = 0; $n < $count; $n++) {
                $nodes[] = [
                    '_lft' => mt_rand(0, 5) === 0 ? null : mt_rand(-20, 60),
                    'parent_id' => match (mt_rand(0, 3)) {
                        0 => null,
                        1 => (string) mt_rand(1, 9),
                        default => mt_rand(1, 9),
                    },
                ];
            }
            $corpus[] = $nodes;
        }

        foreach ($corpus as $nodes) {
            $expected = CategoryTreeBuilder::resolveRootParentIdFromNodesPhp($nodes);
            $this->assertSame($expected, CategoryTreeBuilder::resolveRootParentIdFromNodesNative($nodes), json_encode($nodes) ?: '');
        }
    }

    public function test_root_parent_resolution_caller_matches_original_across_toggles(): void
    {
        $sets = [
            [[1, null, 1, 4], [4, 1, 2, 3], [2, null, 5, 6], [3, null, 7, 8]],
            [[4, 1, 2, 3], [5, 1, 4, 5], [1, null, 1, 6]],
            [[10, 2, 5, 6], [11, 2, 7, 8], [12, 10, 9, 10]],
            [[1, 5, null, null], [2, null, 3, 4], [3, 2, null, null]],
            [[1, '7', '3', '4'], [2, '8', '1', '2']],
            [[1, 3, 2, 3]],
        ];
        $resolve = new ReflectionMethod(CategoryTreeBuilder::class, 'resolveRootParentId');

        foreach ($sets as $rows) {
            $categories = Category::hydrate(array_map(
                static fn (array $row): array => ['id' => $row[0], 'parent_id' => $row[1], '_lft' => $row[2], '_rgt' => $row[3]],
                $rows
            ));

            $expected = null;
            $lowestLeft = null;
            foreach ($rows as [, $parentId, $left]) {
                if ($lowestLeft === null || ($left !== null && $left < $lowestLeft)) {
                    $lowestLeft = $left;
                    $expected = $parentId;
                }
            }

            foreach ([false, true] as $use) {
                self::toggle(CategoryTreeBuilder::class, $use);
                $this->assertSame($expected, $resolve->invoke(new CategoryTreeBuilder, $categories), json_encode($rows) ?: '');
            }
        }
    }

    // ---------------------------------------------------------------------
    // 4. ThrottlesLogins
    // ---------------------------------------------------------------------

    public function test_throttle_key_parity(): void
    {
        $values = [
            '', 'Alice@Example.COM', 'ÄÖÜ@Example.de', 'İstanbul', 'straße', "tab\there", "\x01ctl", "\x7f", "\x10\x13", "\xff\xfe",
            'ＦＵＬＬ', '😀', str_repeat('A', 70), str_repeat('A', 70)."\x01", '127.0.0.1', '::1', 'FE80::1%EN0',
        ];
        mt_srand(2354);
        for ($i = 0; $i < 300; $i++) {
            $values[] = self::randomBytes(80, "AZaz09@.|- \t\r\n\x0B\x01\x10\x13\x7fÉé");
            $values[] = self::randomBytes(20);
        }

        foreach ($values as $i => $username) {
            $ip = $values[($i * 7 + 3) % \count($values)];
            $expected = SecurityForumSupportThrottleFixture::buildThrottleKeyPhp($username, $ip);
            $this->assertSame($expected, SecurityForumSupportThrottleFixture::buildThrottleKeyNative($username, $ip), bin2hex($username.'|'.$ip));
            $this->assertSame(Str::transliterate(Str::lower($username.'|'.$ip)), $expected);
        }
    }

    public function test_throttle_key_caller_matches_original_across_toggles(): void
    {
        $fixture = new SecurityForumSupportThrottleFixture;
        $requests = [
            Request::create('/login', 'POST', ['username' => 'Alice@Example.COM'], [], [], ['REMOTE_ADDR' => '10.0.0.1']),
            Request::create('/login', 'POST', ['username' => 'ÉLÈVE'], [], [], ['REMOTE_ADDR' => '::1']),
            Request::create('/login', 'POST', [], [], [], ['REMOTE_ADDR' => '10.0.0.2']),
            Request::create('/login', 'POST', ['username' => '0'], [], [], ['REMOTE_ADDR' => '10.0.0.3']),
        ];

        foreach ($requests as $request) {
            $original = Str::transliterate(Str::lower($request->input('username').'|'.$request->ip()));
            foreach ([false, true] as $use) {
                self::toggle(SecurityForumSupportThrottleFixture::class, $use);
                $this->assertSame($original, $fixture->keyFor($request));
            }
        }
    }

    public function test_lockout_minutes_parity(): void
    {
        $values = [0, 1, 59, 60, 61, 119, 120, -1, -30, -59, -60, -61, -90, -120, PHP_INT_MAX, PHP_INT_MIN, PHP_INT_MAX - 59, 60 * 9007199254740993, 60 * 9007199254740993 + 1];
        mt_srand(2364);
        for ($i = 0; $i < 2000; $i++) {
            $values[] = mt_rand(-100000, 100000);
            $values[] = mt_rand(0, 1) === 1 ? mt_rand() * mt_rand(1, 1 << 24) * 60 : mt_rand(PHP_INT_MIN, PHP_INT_MAX);
        }

        foreach ($values as $seconds) {
            $original = (int) ceil($seconds / 60);
            $this->assertSame($original, SecurityForumSupportThrottleFixture::computeLockoutMinutesPhp($seconds), (string) $seconds);
            $this->assertSame($original, SecurityForumSupportThrottleFixture::computeLockoutMinutesNative($seconds), (string) $seconds);
        }
    }

    // ---------------------------------------------------------------------
    // 5. TrustedDevice2FAMiddleware
    // ---------------------------------------------------------------------

    public function test_trusted_device_cookie_serialization_parity(): void
    {
        $tokens = [
            '', 'abc', Str::random(64), 'a/b"c\\d', "\x08\x0C\n\r\t\x00\x01\x1F\x7F", 'é😀<>&\'', "\u{2028}\u{2029}\u{FEFF}",
            "\xff", "\xC3", "\xED\xA0\x80", "\xF4\x90\x80\x80", "\xC0\xAF", str_repeat('😀é', 300),
        ];
        mt_srand(2374);
        for ($i = 0; $i < 300; $i++) {
            $tokens[] = self::randomBytes(40, "az09/\\\"\t\n\x00\x1F\x7F<>&'é€😀");
            $tokens[] = self::randomBytes(16);
        }

        $payloads = [
            [], ['user_id' => 1], ['token' => 'x', 'user_id' => 1, 'expires_at' => 2], ['user_id' => '1', 'token' => 'x', 'expires_at' => 2],
            ['user_id' => 1, 'token' => 5, 'expires_at' => 2], ['user_id' => 1, 'token' => 'x', 'expires_at' => 2.5],
            ['user_id' => 1, 'token' => 'x', 'expires_at' => 2, 'extra' => ['a/b' => null]], [1, 'x', 2], ['user_id' => 1, 'token' => "\xff", 'expires_at' => 2, 'x' => 1],
        ];
        foreach ($tokens as $i => $token) {
            $payloads[] = ['user_id' => $i * 7919 - 1000, 'token' => $token, 'expires_at' => 1_893_456_000 + $i];
        }
        $payloads[] = ['user_id' => PHP_INT_MIN, 'token' => 't', 'expires_at' => PHP_INT_MAX];

        foreach ($payloads as $payload) {
            $value = json_encode($payload, JSON_UNESCAPED_SLASHES);
            $expected = $value === false ? null : ['value' => $value, 'minutes' => 43200, 'path' => '/', 'http_only' => true, 'raw' => false];
            $this->assertSame($expected, TrustedDevice2FAMiddleware::serializeTrustedDeviceCookiePhp($payload));
            $this->assertSame($expected, TrustedDevice2FAMiddleware::serializeTrustedDeviceCookieNative($payload), bin2hex(serialize($payload)));
        }
    }

    public function test_middleware_sets_identical_cookie_across_toggles(): void
    {
        $payloads = [
            ['user_id' => 3, 'token' => 'tok/en"é', 'expires_at' => 1_893_456_000],
            ['user_id' => 3, 'token' => "\xff", 'expires_at' => 1],
            'plain-string',
            ['nested' => ['a' => 1]],
        ];
        foreach ($payloads as $payload) {
            $headers = [];
            foreach ([false, true] as $use) {
                self::toggle(TrustedDevice2FAMiddleware::class, $use);
                $request = Request::create('/');
                $request->setLaravelSession(app('session')->driver('array'));
                $request->session()->put('2fa_trusted_device_pending', $payload);
                $response = (new TrustedDevice2FAMiddleware)->handle($request, static fn () => response('ok'));
                $cookies = array_map(static function ($cookie): array {
                    return [$cookie->getName(), $cookie->getValue(), $cookie->getPath(), $cookie->isHttpOnly(), $cookie->isRaw(), $cookie->getSameSite(), $cookie->getExpiresTime() - time()];
                }, $response->headers->getCookies());
                $headers[] = $cookies;
                $this->assertFalse($request->session()->has('2fa_trusted_device_pending'));
            }
            $this->assertSame($headers[0], $headers[1]);
            if (\is_array($payload) && json_encode($payload) === false) {
                $this->assertSame([], $headers[0]);
            } else {
                $this->assertSame('2fa_trusted_device', $headers[0][0][0]);
                $this->assertSame(json_encode($payload, JSON_UNESCAPED_SLASHES), $headers[0][0][1]);
                $this->assertEqualsWithDelta(43200 * 60, $headers[0][0][6], 5);
            }
        }
    }

    // ---------------------------------------------------------------------
    // C-ABI contracts and fallbacks
    // ---------------------------------------------------------------------

    private static function ffiCall(FFI $ffi, string $symbol, mixed ...$args): mixed
    {
        return $ffi->$symbol(...$args);
    }

    public function test_c_abi_null_unaligned_overflow_and_buffer_contracts(): void
    {
        $decline = -2147483648;
        $special = -2147483647;

        $otp = RustPipelineLibrary::resolveDomain('search', Google2FAAuthenticator::FFI_CDEF);
        $this->assertNotNull($otp);
        $small = $otp->new('char[3]');
        $small[0] = 'Z';
        $this->assertSame(-4, self::ffiCall($otp, 'nntmux_2fa_secret_otp', 's', 1, '1 2 3 4', 7, $small, 3));
        $this->assertSame('Z', $small[0]);
        $this->assertSame(3, self::ffiCall($otp, 'nntmux_2fa_secret_otp', 's', 1, '1 2 3', 5, $small, 3));
        $this->assertSame('123', FFI::string($small, 3));
        $this->assertSame($special, self::ffiCall($otp, 'nntmux_2fa_secret_otp', '0', 1, '1', 1, $small, 3));
        $this->assertSame($special, self::ffiCall($otp, 'nntmux_2fa_secret_otp', '', 0, '1', 1, $small, 3));
        $this->assertSame($decline, self::ffiCall($otp, 'nntmux_2fa_secret_otp', null, 1, '1', 1, $small, 3));
        $this->assertSame($decline, self::ffiCall($otp, 'nntmux_2fa_secret_otp', 's', 1, null, 1, $small, 3));
        $this->assertSame($decline, self::ffiCall($otp, 'nntmux_2fa_secret_otp', 's', 1, '1', PHP_INT_MAX, $small, 3));
        $this->assertSame($decline, self::ffiCall($otp, 'nntmux_2fa_secret_otp', 's', 1, '1', 1, null, 3));
        $this->assertSame(0, self::ffiCall($otp, 'nntmux_2fa_secret_otp', 's', 1, ' ', 1, null, 0));

        $perm = RustPipelineLibrary::resolveDomain('search', PermissionSyncHelper::FFI_CDEF);
        $this->assertNotNull($perm);
        $this->assertSame(0b10 | (0b1100 << 16), self::ffiCall($perm, 'nntmux_permission_user_diff', 0b11, 0b01, 0b1100, 4));
        $this->assertSame($decline, self::ffiCall($perm, 'nntmux_permission_user_diff', 0, 0, 0, 16));
        $this->assertSame($decline, self::ffiCall($perm, 'nntmux_permission_user_diff', 0b100, 0, 0, 2));
        $values = $perm->new('int64_t[3]');
        $values[0] = 1;
        $values[1] = 0;
        $values[2] = 2;
        $this->assertSame(0b1 | (0b10 << 16), self::ffiCall($perm, 'nntmux_permission_role_diff', $values, 3, 0b110, 1));
        $this->assertSame(0b1, self::ffiCall($perm, 'nntmux_permission_role_diff', $values, 3, 0b110, 0));
        $this->assertSame($decline, self::ffiCall($perm, 'nntmux_permission_role_diff', $values, 3, 0b1000, 1));
        $this->assertSame($decline, self::ffiCall($perm, 'nntmux_permission_role_diff', $values, 3, 0, 2));
        $this->assertSame($decline, self::ffiCall($perm, 'nntmux_permission_role_diff', null, 3, 0, 1));
        $this->assertSame($decline, self::ffiCall($perm, 'nntmux_permission_role_diff', $values, 16, 0, 1));
        $this->assertSame(0, self::ffiCall($perm, 'nntmux_permission_role_diff', null, 0, 0, 1));
        $raw = $perm->new('uint8_t[64]');
        $this->assertSame($decline, self::ffiCall($perm, 'nntmux_permission_role_diff', $perm->cast('int64_t*', FFI::addr($raw[1])), 2, 0, 1));

        $tree = RustPipelineLibrary::resolveDomain('search', CategoryTreeBuilder::FFI_CDEF);
        $this->assertNotNull($tree);
        $lefts = $tree->new('int64_t[3]');
        $present = $tree->new('uint8_t[3]');
        foreach ([5, 0, 3] as $i => $left) {
            $lefts[$i] = $left;
            $present[$i] = $i === 1 ? 0 : 1;
        }
        $this->assertSame(2, self::ffiCall($tree, 'nntmux_category_root_index', $lefts, $present, 3));
        $this->assertSame(-1, self::ffiCall($tree, 'nntmux_category_root_index', null, null, 0));
        $this->assertSame($decline, self::ffiCall($tree, 'nntmux_category_root_index', null, $present, 3));
        $this->assertSame($decline, self::ffiCall($tree, 'nntmux_category_root_index', $lefts, null, 3));
        $present[1] = 2;
        $this->assertSame($decline, self::ffiCall($tree, 'nntmux_category_root_index', $lefts, $present, 3));
        $rawTree = $tree->new('uint8_t[64]');
        $this->assertSame($decline, self::ffiCall($tree, 'nntmux_category_root_index', $tree->cast('int64_t*', FFI::addr($rawTree[3])), $present, 2));
        $this->assertSame($decline, self::ffiCall($tree, 'nntmux_category_root_index', $lefts, $present, PHP_INT_MAX));

        $throttle = RustPipelineLibrary::resolveDomain('search', SecurityForumSupportThrottleFixture::THROTTLE_FFI_CDEF);
        $this->assertNotNull($throttle);
        $key = $throttle->new('char[2]');
        $key[0] = 'K';
        $this->assertSame(-4, self::ffiCall($throttle, 'nntmux_throttle_key', 'AB', 2, 'c', 1, $key, 2));
        $this->assertSame('K', $key[0]);
        $this->assertSame($decline, self::ffiCall($throttle, 'nntmux_throttle_key', "\x01", 1, '', 0, $key, 2));
        $this->assertSame($decline, self::ffiCall($throttle, 'nntmux_throttle_key', 'é', 2, '', 0, $key, 2));
        $this->assertSame($decline, self::ffiCall($throttle, 'nntmux_throttle_key', null, 1, '', 0, $key, 2));
        $this->assertSame($decline, self::ffiCall($throttle, 'nntmux_throttle_key', 'a', PHP_INT_MAX, '', 0, $key, 2));
        $this->assertSame($decline, self::ffiCall($throttle, 'nntmux_throttle_key', '', 0, '', 0, null, 2));
        $this->assertSame(1, self::ffiCall($throttle, 'nntmux_throttle_lockout_minutes', 60));
        $this->assertSame(153722867280912928, self::ffiCall($throttle, 'nntmux_throttle_lockout_minutes', PHP_INT_MAX));

        $cookie = RustPipelineLibrary::resolveDomain('search', TrustedDevice2FAMiddleware::FFI_CDEF);
        $this->assertNotNull($cookie);
        $json = $cookie->new('char[4]');
        $json[0] = 'J';
        $this->assertSame(-40, self::ffiCall($cookie, 'nntmux_trusted_device_cookie_json', 1, 't', 1, 2, $json, 4));
        $this->assertSame('J', $json[0]);
        $this->assertSame($special, self::ffiCall($cookie, 'nntmux_trusted_device_cookie_json', 1, "\xff", 1, 2, $json, 4));
        $this->assertSame($decline, self::ffiCall($cookie, 'nntmux_trusted_device_cookie_json', 1, null, 1, 2, $json, 4));
        $this->assertSame($decline, self::ffiCall($cookie, 'nntmux_trusted_device_cookie_json', 1, 't', PHP_INT_MAX, 2, $json, 4));
        $this->assertSame($decline, self::ffiCall($cookie, 'nntmux_trusted_device_cookie_json', 1, 't', 1, 2, null, 64));
    }

    public function test_native_methods_fall_back_to_php_when_ffi_unavailable(): void
    {
        foreach (self::TOGGLED_CLASSES as $class) {
            $class::setFfiForTesting(false);
            $class::enableRustFfi();
        }

        $this->assertSame('123456', Google2FAAuthenticator::validateSecretAndNormalizeOtpNative(self::SECRET, ' 123 456 '));
        $this->assertSame(
            ['grant' => ['view console'], 'revoke' => ['view movies']],
            PermissionSyncHelper::computeUserPermissionDiffNative(['viewconsole'], [], ['view movies'])
        );
        $this->assertSame(
            ['grant' => ['preview'], 'revoke' => ['hideads']],
            PermissionSyncHelper::computeRolePermissionDiffNative(['canpreview' => 1], ['hideads'], true)
        );
        $this->assertSame(2, CategoryTreeBuilder::resolveRootParentIdFromNodesNative([['_lft' => 2, 'parent_id' => 1], ['_lft' => 1, 'parent_id' => 2]]));
        $this->assertSame('a@b.c|::1', SecurityForumSupportThrottleFixture::buildThrottleKeyNative('A@B.C', '::1'));
        $this->assertSame(2, SecurityForumSupportThrottleFixture::computeLockoutMinutesNative(61));
        $this->assertSame(
            '{"user_id":1,"token":"a/b","expires_at":2}',
            TrustedDevice2FAMiddleware::serializeTrustedDeviceCookieNative(['user_id' => 1, 'token' => 'a/b', 'expires_at' => 2])['value'] ?? null
        );
    }

    // ---------------------------------------------------------------------
    // Paired Randomized Benchmarks
    // ---------------------------------------------------------------------

    public function test_paired_randomized_benchmarks(): void
    {
        if (getenv('NNTMUX_SECURITY_FORUM_SUPPORT_BENCH') !== '1') {
            $this->markTestSkipped('Set NNTMUX_SECURITY_FORUM_SUPPORT_BENCH=1 to run paired Rust/PHP benchmarks.');
        }

        $longOtp = str_repeat(" 12\t34 ", 300);
        $allInputs = array_keys(self::VIEW_INPUTS);
        $allPermissions = array_values(self::VIEW_INPUTS);
        $manyPermissions = [...array_map(static fn (int $i): string => "custom permission {$i}", range(1, 60)), ...$allPermissions];
        $commonValues = array_map(static fn (string $p): int => 1, self::ROLE_INPUTS);
        $uncommonValues = array_map(static fn (string $p): int => \strlen($p) % 3, self::ROLE_INPUTS);
        $commonNodes = [];
        for ($i = 0; $i < 8; $i++) {
            $commonNodes[] = ['_lft' => 2 * $i + 1, 'parent_id' => $i < 4 ? null : $i - 3];
        }
        mt_srand(2384);
        $uncommonNodes = [];
        for ($i = 0; $i < 2000; $i++) {
            $uncommonNodes[] = ['_lft' => mt_rand(0, 9) === 0 ? null : mt_rand(1, 100000), 'parent_id' => mt_rand(1, 500)];
        }
        $longUser = str_repeat('Some.Very.Long.User.Name@Example.COM', 6);
        $commonCookie = ['user_id' => 42, 'token' => Str::random(64), 'expires_at' => 1_893_456_000];
        $uncommonCookie = ['user_id' => 42, 'token' => str_repeat('tok/en"é😀\\', 100), 'expires_at' => 1_893_456_000];

        $workloads = [
            'Google2FAAuthenticator::validateSecretAndNormalizeOtp' => [
                Google2FAAuthenticator::class,
                static fn () => Google2FAAuthenticator::validateSecretAndNormalizeOtp(self::SECRET, '123456'),
                static fn () => Google2FAAuthenticator::validateSecretAndNormalizeOtp(self::SECRET, $longOtp),
            ],
            'PermissionSyncHelper::computeUserPermissionDiff' => [
                PermissionSyncHelper::class,
                static fn () => PermissionSyncHelper::computeUserPermissionDiff(\array_slice($allInputs, 0, 5), \array_slice($allPermissions, 0, 3), \array_slice($allPermissions, 2)),
                static fn () => PermissionSyncHelper::computeUserPermissionDiff([], $manyPermissions, $manyPermissions),
            ],
            'PermissionSyncHelper::computeRolePermissionDiff' => [
                PermissionSyncHelper::class,
                static fn () => PermissionSyncHelper::computeRolePermissionDiff($commonValues, [], false),
                static fn () => PermissionSyncHelper::computeRolePermissionDiff($uncommonValues, $manyPermissions, true),
            ],
            'CategoryTreeBuilder::resolveRootParentIdFromNodes' => [
                CategoryTreeBuilder::class,
                static fn () => CategoryTreeBuilder::resolveRootParentIdFromNodes($commonNodes),
                static fn () => CategoryTreeBuilder::resolveRootParentIdFromNodes($uncommonNodes),
            ],
            'ThrottlesLogins::buildThrottleKey' => [
                SecurityForumSupportThrottleFixture::class,
                static fn () => SecurityForumSupportThrottleFixture::buildThrottleKey('User@Example.com', '192.168.1.10'),
                static fn () => SecurityForumSupportThrottleFixture::buildThrottleKey($longUser, '2001:0DB8:85A3:0000:0000:8A2E:0370:7334'),
            ],
            'ThrottlesLogins::computeLockoutMinutes' => [
                SecurityForumSupportThrottleFixture::class,
                static fn () => SecurityForumSupportThrottleFixture::computeLockoutMinutes(59),
                static fn () => SecurityForumSupportThrottleFixture::computeLockoutMinutes(PHP_INT_MAX - 7),
            ],
            'TrustedDevice2FAMiddleware::serializeTrustedDeviceCookie' => [
                TrustedDevice2FAMiddleware::class,
                static fn () => TrustedDevice2FAMiddleware::serializeTrustedDeviceCookie($commonCookie),
                static fn () => TrustedDevice2FAMiddleware::serializeTrustedDeviceCookie($uncommonCookie),
            ],
        ];

        $rows = [];
        foreach ($workloads as $name => [$class, $common, $uncommon]) {
            $worst = ['common_mean' => INF, 'common_p99' => INF, 'uncommon_mean' => INF, 'uncommon_p99' => INF];
            for ($rep = 0; $rep < 3; $rep++) {
                foreach (['common' => $common, 'uncommon' => $uncommon] as $kind => $fn) {
                    [$rustOut, $phpOut] = self::bothWays($class, $fn);
                    $this->assertSame($phpOut, $rustOut, "{$name} {$kind} benchmark output parity");

                    $stats = $this->runPairedBenchmark($class, $fn, 200, 41, 2304 + $rep);
                    fwrite(STDERR, json_encode(['method' => $name, 'workload' => $kind, 'repetition' => $rep] + $stats).PHP_EOL);
                    $worst["{$kind}_mean"] = min($worst["{$kind}_mean"], $stats['mean_speedup']);
                    $worst["{$kind}_p99"] = min($worst["{$kind}_p99"], $stats['p99_speedup']);
                }
            }
            $class::resetRustFfi();
            $qualified = min($worst) >= 1.5;
            $rows[] = sprintf(
                ' * | %-55s | %.2fx / %.2fx       | %.2fx / %.2fx         | %-7s | %-11s |',
                $name,
                $worst['common_mean'],
                $worst['common_p99'],
                $worst['uncommon_mean'],
                $worst['uncommon_p99'],
                $qualified ? 'Yes' : 'No',
                $qualified ? 'Rust (true)' : 'PHP (false)',
            );
            if ($class::usesRustFfi()) {
                $this->assertTrue($qualified, "{$name} defaults to Rust but misses the 1.5x gate");
            }
        }

        fwrite(STDERR, implode(PHP_EOL, $rows).PHP_EOL);
    }

    /**
     * @param  class-string  $class
     * @return array{rust_mean_us: float, php_mean_us: float, rust_p99_us: float, php_p99_us: float, mean_speedup: float, p99_speedup: float, samples: int, inner_loops: int, warmup: int}
     */
    private function runPairedBenchmark(string $class, Closure $fn, int $innerLoops, int $samples, int $seed): array
    {
        $warmup = 50;
        foreach ([true, false] as $use) {
            self::toggle($class, $use);
            for ($w = 0; $w < $warmup; $w++) {
                $fn();
            }
        }

        $times = [1 => [], 0 => []];
        mt_srand($seed);
        for ($s = 0; $s < $samples; $s++) {
            $order = mt_rand(0, 1) === 0 ? [true, false] : [false, true];
            foreach ($order as $use) {
                self::toggle($class, $use);
                $t0 = hrtime(true);
                for ($i = 0; $i < $innerLoops; $i++) {
                    $fn();
                }
                $times[(int) $use][] = (hrtime(true) - $t0) / 1000.0 / $innerLoops;
            }
        }

        $rust = $times[1];
        $php = $times[0];
        sort($rust);
        sort($php);
        $p99 = (int) floor((\count($rust) - 1) * 0.99);
        $rustMean = array_sum($rust) / \count($rust);
        $phpMean = array_sum($php) / \count($php);

        return [
            'rust_mean_us' => $rustMean,
            'php_mean_us' => $phpMean,
            'rust_p99_us' => $rust[$p99],
            'php_p99_us' => $php[$p99],
            'mean_speedup' => $phpMean / $rustMean,
            'p99_speedup' => $php[$p99] / $rust[$p99],
            'samples' => $samples,
            'inner_loops' => $innerLoops,
            'warmup' => $warmup,
        ];
    }
}

/**
 * Minimal `ThrottlesLogins` consumer exposing the trait's toggles and throttle key.
 */
final class SecurityForumSupportThrottleFixture
{
    use ThrottlesLogins;

    protected ?int $maxAttempts = null;

    protected ?int $decayMinutes = null;

    public function username(): string
    {
        return 'username';
    }

    public function keyFor(Request $request): string
    {
        return $this->throttleKey($request);
    }
}
