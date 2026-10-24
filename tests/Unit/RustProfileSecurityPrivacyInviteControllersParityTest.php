<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Http\Controllers\InvitationController;
use App\Http\Controllers\PasswordSecurityController;
use App\Http\Controllers\PrivacyCenterController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ProfileSecurityController;
use App\Support\RustPipelineLibrary;
use Closure;
use FFI;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Parity, C-ABI contract, fallback, and paired benchmark verification for Wave 28 Slice 28.5:
 * ProfileController, PasswordSecurityController, ProfileSecurityController,
 * PrivacyCenterController and InvitationController helpers.
 *
 * Benchmarks run only with NNTMUX_PROFILE_SECURITY_PRIVACY_INVITE_BENCH=1:
 *   CARGO_TARGET_DIR=/tmp/cargo-w28-5 cargo build --manifest-path rust/pipeline-ffi/Cargo.toml --release
 *   NNTMUX_PIPELINE_FFI_LIB=/tmp/cargo-w28-5/release/libnntmux_pipeline.dylib \
 *     NNTMUX_PROFILE_SECURITY_PRIVACY_INVITE_BENCH=1 php artisan test --compact tests/Unit/RustProfileSecurityPrivacyInviteControllersParityTest.php
 *
 * Paired randomized benchmark evidence (3 repetitions x 41 paired samples x 200 inner loops,
 * worst-repetition PHP latency / Rust latency, FFI call overhead included):
 *
 * Apple M5 (arm64), PHP 8.5.12 NTS, release cdylib, base commit 60e8c15d5. Raw per-repetition
 * JSON lines are written to STDERR by the benchmark test. No helper reaches 1.5x, so every
 * class keeps the PHP default.
 *
 * | Method                                                 | Common (Mean / P99) | Uncommon (Mean / P99) | >= 1.5x | Default     |
 * |--------------------------------------------------------|---------------------|-----------------------|---------|-------------|
 * | ProfileController::profileLookupParameters             | 0.19x / 0.17x       | 0.64x / 0.56x         | No      | PHP (false) |
 * | ProfileController::isAllowedThemeValue                 | 0.26x / 0.24x       | 0.33x / 0.30x         | No      | PHP (false) |
 * | ProfileController::excludedCategoryIds                 | 0.22x / 0.17x       | 0.51x / 0.52x         | No      | PHP (false) |
 * | ProfileController::cacheKey                            | 0.17x / 0.18x       | 0.19x / 0.22x         | No      | PHP (false) |
 * | PasswordSecurityController::isProfileReferer           | 0.23x / 0.28x       | 0.95x / 0.66x         | No      | PHP (false) |
 * | PasswordSecurityController::trustedDeviceCookiePayload | 0.29x / 0.28x       | 0.58x / 0.48x         | No      | PHP (false) |
 * | PasswordSecurityController::canCancelSetup             | 0.23x / 0.21x       | 0.21x / 0.26x         | No      | PHP (false) |
 * | ProfileSecurityController::disable2faResponseState     | 0.12x / 0.14x       | 0.13x / 0.12x         | No      | PHP (false) |
 * | PrivacyCenterController::exportRequestPayload          | 0.08x / 0.10x       | 0.09x / 0.09x         | No      | PHP (false) |
 * | PrivacyCenterController::erasureRequestPayload         | 0.08x / 0.08x       | 0.15x / 0.16x         | No      | PHP (false) |
 * | PrivacyCenterController::canDownloadExport             | 0.25x / 0.33x       | 0.24x / 0.18x         | No      | PHP (false) |
 * | PrivacyCenterController::exportDownloadFilename        | 0.19x / 0.19x       | 0.24x / 0.28x         | No      | PHP (false) |
 * | InvitationController::availableInvites                 | 0.19x / 0.20x       | 0.18x / 0.20x         | No      | PHP (false) |
 * | InvitationController::normalizeInvitationRow           | 0.39x / 0.43x       | 0.46x / 0.41x         | No      | PHP (false) |
 * | InvitationController::previewRoleName                  | 0.06x / 0.07x       | 0.01x / 0.00x         | No      | PHP (false) |
 */
class RustProfileSecurityPrivacyInviteControllersParityTest extends TestCase
{
    /** @var list<class-string> */
    private const array TOGGLED_CLASSES = [
        ProfileController::class,
        PasswordSecurityController::class,
        ProfileSecurityController::class,
        PrivacyCenterController::class,
        InvitationController::class,
    ];

    private const int DECLINE = -2147483648;

    private const int JSON_INVALID_UTF8 = -2147483647;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resetFfiState();
        if (self::library(ProfileController::FFI_CDEF) === null) {
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
            $class::setUseRustFfi(null);
            $class::setFfiForTesting(null);
        }
    }

    private static function library(string $cdef): ?FFI
    {
        $path = getenv('NNTMUX_PIPELINE_FFI_LIB');

        return RustPipelineLibrary::resolveDomain('search', $cdef, \is_string($path) && $path !== '' ? $path : null);
    }

    /**
     * Point every toggled class at the library under test.
     */
    private function useLibraryUnderTest(): void
    {
        foreach (self::TOGGLED_CLASSES as $class) {
            $ffi = self::library($class::FFI_CDEF);
            $this->assertNotNull($ffi, $class);
            $class::setFfiForTesting($ffi);
        }
    }

    private static function ffiCall(FFI $ffi, string $symbol, mixed ...$args): mixed
    {
        return $ffi->$symbol(...$args);
    }

    /**
     * Assert the PHP and native helpers, and the toggled dispatcher in both modes, all return `$expected`.
     *
     * @param  class-string  $class
     * @param  list<mixed>  $args
     */
    private function assertAllPaths(string $class, string $method, array $args, mixed $expected, string $message = ''): void
    {
        $this->assertSame($expected, $class::{$method.'Php'}(...$args), $message);
        $this->assertSame($expected, $class::{$method.'Native'}(...$args), $message);
        foreach ([true, false] as $use) {
            $class::setUseRustFfi($use);
            $this->assertSame($expected, $class::$method(...$args), $message);
        }
        $class::setUseRustFfi(null);
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

    public function test_toggle_api_accepts_nullable_bool(): void
    {
        foreach (self::TOGGLED_CLASSES as $class) {
            $class::setUseRustFfi(true);
            $this->assertTrue($class::usesRustFfi());
            $class::setUseRustFfi(false);
            $this->assertFalse($class::usesRustFfi());
            $class::setUseRustFfi(true);
            $class::setUseRustFfi(null);
            $this->assertFalse($class::usesRustFfi());
        }
    }

    // ---------------------------------------------------------------------
    // 1. ProfileController
    // ---------------------------------------------------------------------

    /**
     * @return array{mode: int, id: int|null}
     */
    private static function lookupReference(string $id, string $name): array
    {
        $altId = $id !== '' && preg_match('/^\d+$/', $id) === 1 ? (int) $id : false;
        $altName = $name !== '' ? $name : false;
        if ($altId === false && $altName !== false) {
            return ['mode' => ProfileController::LOOKUP_BY_NAME, 'id' => null];
        }
        if ($altId !== false) {
            return ['mode' => ProfileController::LOOKUP_BY_ID, 'id' => $altId];
        }

        return ['mode' => ProfileController::LOOKUP_NONE, 'id' => null];
    }

    public function test_profile_lookup_parameters_parity(): void
    {
        $this->useLibraryUnderTest();
        $ids = ['', '0', '42', '007', "42\n", "42\n\n", "\n", ' 42', '42 ', '-1', '+1', '1.0', '1e3', 'abc', '٣',
            '9223372036854775807', '9223372036854775808', '99999999999999999999999', str_repeat('0', 40).'5', "4\x002"];
        $names = ['', 'alice', '0', ' ', "nul\0name", 'ünïcode'];
        foreach ($ids as $id) {
            foreach ($names as $name) {
                $this->assertAllPaths(ProfileController::class, 'profileLookupParameters', [$id, $name], self::lookupReference($id, $name), json_encode([$id, $name]) ?: '');
            }
        }
        $this->assertSame(['mode' => ProfileController::LOOKUP_BY_NAME, 'id' => null], ProfileController::profileLookupParametersNative(str_repeat('1', (1 << 20) + 1).'x', 'bob'));
    }

    public function test_profile_lookup_randomized_parity(): void
    {
        $this->useLibraryUnderTest();
        $alphabet = ['0', '1', '9', "\n", ' ', 'a', '-', '+', '.', "\0"];
        mt_srand(2851);
        for ($round = 0; $round < 500; $round++) {
            $id = '';
            for ($i = mt_rand(0, 22); $i > 0; $i--) {
                $id .= $alphabet[mt_rand(0, mt_rand(0, 3) === 0 ? \count($alphabet) - 1 : 2)];
            }
            $name = mt_rand(0, 1) === 1 ? 'n'.$round : '';
            $this->assertSame(self::lookupReference($id, $name), ProfileController::profileLookupParametersNative($id, $name), json_encode($id) ?: '');
        }
    }

    public function test_theme_value_whitelist_parity(): void
    {
        $this->useLibraryUnderTest();
        $themes = ['light', 'dark', 'system'];
        $schemes = ['blue', 'indigo', 'cyan', 'teal', 'emerald', 'violet', 'pink', 'rose', 'red', 'orange', 'amber'];
        $values = [...$themes, ...$schemes, '', 'Light', 'dark ', "system\0", 'purple', null, 0, 1, true, false, 1.5, ['dark'], (object) []];
        foreach ([ProfileController::THEME_PREFERENCE => $themes, ProfileController::COLOR_SCHEME => $schemes] as $kind => $allowed) {
            foreach ($values as $value) {
                $this->assertAllPaths(ProfileController::class, 'isAllowedThemeValue', [$kind, $value], \in_array($value, $allowed, true), (string) json_encode($value));
            }
        }
        foreach ([2, -1] as $kind) {
            $this->assertAllPaths(ProfileController::class, 'isAllowedThemeValue', [$kind, 'dark'], false);
        }
    }

    /**
     * @return array<string, array{0: array<array-key, mixed>}>
     */
    public static function excludedCategoryProvider(): array
    {
        return [
            'empty' => [[]],
            'integer strings' => [['5010', '5020', '2040']],
            'native integers' => [[5010, 5020]],
            'mixed types' => [[5010, '5020', null, true, false, 1.9, -2.5, [1], 'abc', '', ' ', '7 ', ' 8', "\t9\n"]],
            'string keys preserved' => [['a' => '1', 'b' => 'x', 'c' => '3', 10 => '4']],
            'float strings' => [['1.5', '1.', '.5', '1e3', '-1.e-3', ' .5E+2 ', '1e', '1e+', 'e5', '.']],
            'signs and zeros' => [['-0', '+7', '007', '-7', '--1', '- 1', '+']],
            'integer limits' => [['9223372036854775807', '-9223372036854775808', '9223372036854775808', '-9223372036854775809', '99999999999999999999999']],
            'non numeric strings' => [['0x1A', '1_000', '1 2', "\x001", "1\x00", 'INF', 'NAN', '1e5.5', '٣', "1\v", "\f1"]],
            'huge float strings' => [['1e30', '-1e30', '1e999', '1.5e18']],
        ];
    }

    /**
     * @param  array<array-key, mixed>  $values
     */
    #[DataProvider('excludedCategoryProvider')]
    public function test_excluded_category_ids_parity(array $values): void
    {
        $this->useLibraryUnderTest();
        $expected = array_map('intval', array_filter($values, 'is_numeric'));
        $this->assertAllPaths(ProfileController::class, 'excludedCategoryIds', [$values], $expected);
        $this->assertSame(array_keys($expected), array_keys(ProfileController::excludedCategoryIdsNative($values)));
    }

    public function test_excluded_category_ids_randomized_parity(): void
    {
        $this->useLibraryUnderTest();
        $pieces = ['0', '1', '5', '9', ' ', "\t", "\n", '-', '+', '.', 'e', 'E', 'x', "\0", "\v", "\f"];
        mt_srand(2852);
        for ($round = 0; $round < 200; $round++) {
            $values = [];
            for ($n = mt_rand(0, 12); $n > 0; $n--) {
                $value = '';
                for ($i = mt_rand(0, 24); $i > 0; $i--) {
                    $value .= $pieces[mt_rand(0, mt_rand(0, 2) === 0 ? \count($pieces) - 1 : 9)];
                }
                $values[mt_rand(0, 3) === 0 ? 'k'.$n : $n * 3] = match (mt_rand(0, 9)) {
                    0 => mt_rand(-1000, 1000),
                    1 => mt_rand(-1000, 1000) / 7,
                    2 => null,
                    default => $value,
                };
            }
            $this->assertSame(
                array_map('intval', array_filter($values, 'is_numeric')),
                ProfileController::excludedCategoryIdsNative($values),
                (string) json_encode(array_map(static fn (mixed $v): mixed => \is_string($v) ? bin2hex($v) : $v, $values)),
            );
        }
    }

    public function test_cache_key_parity(): void
    {
        $this->useLibraryUnderTest();
        foreach ([0, 1, 42, -5, PHP_INT_MAX, PHP_INT_MIN] as $id) {
            $this->assertAllPaths(ProfileController::class, 'cacheKey', [ProfileController::CACHE_COMPOSER_USER, $id], 'composer_user_'.$id);
            $this->assertAllPaths(ProfileController::class, 'cacheKey', [ProfileController::CACHE_CATEGORY_EXCLUSIONS, $id], 'user_category_exclusions_'.$id);
        }
        try {
            ProfileController::cacheKeyPhp(2, 1);
            $this->fail('cacheKeyPhp must reject an unknown kind');
        } catch (InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }
        try {
            ProfileController::cacheKeyNative(2, 1);
            $this->fail('cacheKeyNative must reject an unknown kind');
        } catch (InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }
    }

    // ---------------------------------------------------------------------
    // 2. PasswordSecurityController
    // ---------------------------------------------------------------------

    public function test_profile_referer_parity(): void
    {
        $this->useLibraryUnderTest();
        $referers = [null, '', '0', 'profileedit', 'https://nntmux.test/profileedit#security', 'https://nntmux.test/profileEdit',
            'profileedi', 'https://nntmux.test/2fa', "profile\0edit", str_repeat('x', 3000).'profileedit'];
        foreach ([true, false] as $fromProfile) {
            foreach ($referers as $referer) {
                $expected = $fromProfile || ($referer && str_contains($referer, 'profileedit'));
                $this->assertAllPaths(PasswordSecurityController::class, 'isProfileReferer', [$fromProfile, $referer], $expected, (string) $referer);
            }
        }
        $this->assertFalse(PasswordSecurityController::isProfileRefererNative(false, str_repeat('p', (1 << 20) + 1)));
    }

    /**
     * @return array<string, array{0: int, 1: string, 2: int}>
     */
    public static function trustedCookieProvider(): array
    {
        return [
            'random token' => [7, 'aB3dE5fG7hJ9kL1mN3pQ5rS7tU9vW1xY3zA5bC7dE9fG1hJ3kL5mN7pQ9rS1tU3v', 1_700_000_000],
            'empty token' => [1, '', 0],
            'escapes' => [-1, "a/\"\\\x08\x0c\n\r\t\x01\x1f\x7f<>&'", PHP_INT_MIN],
            'unicode' => [PHP_INT_MAX, 'é€😀'."\u{2028}\u{2029}\u{FEFF}", PHP_INT_MAX],
            'invalid utf8' => [1, "\xff", 1],
            'surrogate bytes' => [1, "\xed\xa0\x80", 1],
            'overlong slash' => [1, "\xc0\xaf", 1],
            'truncated sequence' => [1, "ok\xe2\x82", 1],
            'long token' => [5, str_repeat('🔑x"', 2000), 1_800_000_000],
        ];
    }

    #[DataProvider('trustedCookieProvider')]
    public function test_trusted_device_cookie_payload_parity(int $userId, string $token, int $expiresAt): void
    {
        $this->useLibraryUnderTest();
        $encoded = json_encode(['user_id' => $userId, 'token' => $token, 'expires_at' => $expiresAt], JSON_UNESCAPED_SLASHES);
        $this->assertAllPaths(PasswordSecurityController::class, 'trustedDeviceCookiePayload', [$userId, $token, $expiresAt], $encoded === false ? null : $encoded);
    }

    public function test_trusted_device_cookie_randomized_byte_parity(): void
    {
        $this->useLibraryUnderTest();
        mt_srand(2853);
        for ($round = 0; $round < 400; $round++) {
            $token = '';
            for ($i = mt_rand(0, 16); $i > 0; $i--) {
                $token .= mt_rand(0, 2) === 0 ? \chr(mt_rand(0, 255)) : (string) mb_chr(mt_rand(0x20, 0x2FFF));
            }
            $encoded = json_encode(['user_id' => $round, 'token' => $token, 'expires_at' => -$round], JSON_UNESCAPED_SLASHES);
            $this->assertSame($encoded === false ? null : $encoded, PasswordSecurityController::trustedDeviceCookiePayloadNative($round, $token, -$round), bin2hex($token));
        }
    }

    public function test_cancel_setup_eligibility_parity(): void
    {
        $this->useLibraryUnderTest();
        foreach ([true, false] as $exists) {
            foreach ([true, false] as $enabled) {
                $this->assertAllPaths(PasswordSecurityController::class, 'canCancelSetup', [$exists, $enabled], $exists && ! $enabled);
            }
        }
    }

    // ---------------------------------------------------------------------
    // 3. ProfileSecurityController
    // ---------------------------------------------------------------------

    public function test_disable2fa_response_state_parity(): void
    {
        $this->useLibraryUnderTest();
        foreach ([true, false] as $matches) {
            foreach ([true, false] as $hasSecurity) {
                foreach ([true, false] as $json) {
                    [$outcome, $message] = match (true) {
                        ! $matches => [ProfileSecurityController::DISABLE_PASSWORD_MISMATCH, 'Your password does not match. Please try again.'],
                        $hasSecurity => [ProfileSecurityController::DISABLE_SUCCESS, '2FA has been successfully disabled.'],
                        default => [ProfileSecurityController::DISABLE_MISSING, 'No 2FA configuration found for this user.'],
                    };
                    $success = $outcome === ProfileSecurityController::DISABLE_SUCCESS;
                    $expected = [
                        'outcome' => $outcome,
                        'success' => $success,
                        'json' => $json,
                        'flash_key' => $success ? 'success_2fa' : 'error_2fa',
                        'message' => $message,
                    ];
                    $this->assertAllPaths(ProfileSecurityController::class, 'disable2faResponseState', [$matches, $hasSecurity, $json], $expected);
                }
            }
        }
    }

    // ---------------------------------------------------------------------
    // 4. PrivacyCenterController
    // ---------------------------------------------------------------------

    public function test_gdpr_request_payload_parity(): void
    {
        $this->useLibraryUnderTest();
        foreach ([true, false] as $ipRecorded) {
            $this->assertAllPaths(PrivacyCenterController::class, 'exportRequestPayload', [$ipRecorded], ['requested_by' => 'self_service', 'ip_recorded' => $ipRecorded]);
        }
        foreach (['ERASE', '', "ER\0ASE", 'ü', str_repeat('E', 5000)] as $confirmation) {
            $this->assertAllPaths(PrivacyCenterController::class, 'erasureRequestPayload', [$confirmation], ['requested_by' => 'self_service', 'confirmation' => $confirmation]);
        }
        $this->assertSame(['requested_by' => 'self_service', 'confirmation' => str_repeat('E', (1 << 20) + 1)], PrivacyCenterController::erasureRequestPayloadNative(str_repeat('E', (1 << 20) + 1)));
    }

    public function test_export_download_authorization_and_filename_parity(): void
    {
        $this->useLibraryUnderTest();
        foreach ([[1, 1], [1, 2], [0, 0], [PHP_INT_MIN, PHP_INT_MIN], [PHP_INT_MAX, PHP_INT_MIN]] as [$owner, $user]) {
            foreach ([true, false] as $downloadable) {
                $this->assertAllPaths(PrivacyCenterController::class, 'canDownloadExport', [$owner, $user, $downloadable], $owner === $user && $downloadable);
            }
        }
        foreach ([0, 1, 42, -5, PHP_INT_MAX, PHP_INT_MIN] as $id) {
            $this->assertAllPaths(PrivacyCenterController::class, 'exportDownloadFilename', [$id], 'gdpr-export-'.$id.'.json');
        }
    }

    // ---------------------------------------------------------------------
    // 5. InvitationController
    // ---------------------------------------------------------------------

    public function test_available_invites_parity(): void
    {
        $this->useLibraryUnderTest();
        $cases = [[5, 2], [2, 2], [0, 0], [1, 3], [-1, 0], [PHP_INT_MAX, 0], [PHP_INT_MIN, 1], [PHP_INT_MAX, -1], [PHP_INT_MIN, PHP_INT_MAX]];
        foreach ($cases as [$total, $pending]) {
            $available = $total - $pending;
            $this->assertAllPaths(InvitationController::class, 'availableInvites', [$total, $pending], ['available' => $available, 'can_send_invites' => $available > 0], "{$total}-{$pending}");
        }
        $this->assertIsFloat(InvitationController::availableInvitesNative(PHP_INT_MIN, 1)['available']);
    }

    public function test_invitation_row_normalization_parity(): void
    {
        $this->useLibraryUnderTest();
        $row = ['id' => 3, 'token' => 'abc', 'created_at' => '2026-10-01T00:00:00.000000Z', 'expires_at' => '2026-10-08T00:00:00.000000Z', 'used_at' => null, 'used_by' => null];
        $usedBy = ['id' => 9, 'username' => 'bob'];
        foreach ([null, $usedBy] as $usedByUser) {
            foreach ([null, 0, 1_760_000_000] as $usedAt) {
                foreach ([[1_759_000_000, 1_759_600_000], [null, null]] as [$created, $expires]) {
                    foreach ([$row, ['id' => 4], []] as $input) {
                        $expected = $input;
                        if ($usedByUser !== null) {
                            $expected['used_by_user'] = $usedByUser;
                        }
                        $expected['created_at'] = $created;
                        $expected['expires_at'] = $expires;
                        if ($usedAt !== null) {
                            $expected['used_at'] = $usedAt;
                        }
                        $args = [$input, $usedByUser, $created, $expires, $usedAt];
                        $this->assertAllPaths(InvitationController::class, 'normalizeInvitationRow', $args, $expected);
                        $this->assertSame(array_keys($expected), array_keys(InvitationController::normalizeInvitationRowNative(...$args)));
                    }
                }
            }
        }
    }

    public function test_preview_role_name_parity(): void
    {
        $this->useLibraryUnderTest();
        $roleSets = [
            [],
            [1 => 'User', 2 => 'Admin', 3 => 'Disabled', 4 => 'Moderator', 5 => 'Friend'],
            ['02' => 'Zero-padded', 2 => 'Two', 'admin' => 'Named', -3 => 'Negative', 'x' => null, 7 => 70],
            [0 => 'Zero', '' => 'Empty', ' 1' => 'Spaced', '1.5' => 'Float string'],
        ];
        $roles = [1, 2, 3, 6, 0, -3, '1', '2', '02', '-3', '-0', 'admin', 'x', '7', '', ' 1', '1.5', '0', "2\0"];
        foreach ($roleSets as $roleSet) {
            foreach ($roles as $role) {
                $this->assertAllPaths(InvitationController::class, 'previewRoleName', [$role, $roleSet], $roleSet[$role] ?? 'Default', (string) json_encode([$role, $roleSet]));
            }
        }
        $roleSet = [1 => 'User', 0 => 'Zero'];
        foreach ([true, false, null, 1.0] as $role) {
            $this->assertSame(InvitationController::previewRoleNamePhp($role, $roleSet), InvitationController::previewRoleNameNative($role, $roleSet));
        }
    }

    // ---------------------------------------------------------------------
    // C-ABI contract
    // ---------------------------------------------------------------------

    public function test_c_abi_pointer_and_capacity_contract(): void
    {
        $profile = self::library(ProfileController::FFI_CDEF);
        $password = self::library(PasswordSecurityController::FFI_CDEF);
        $security = self::library(ProfileSecurityController::FFI_CDEF);
        $privacy = self::library(PrivacyCenterController::FFI_CDEF);
        $invitation = self::library(InvitationController::FFI_CDEF);
        $this->assertNotNull($profile);
        $this->assertNotNull($password);
        $this->assertNotNull($security);
        $this->assertNotNull($privacy);
        $this->assertNotNull($invitation);
        $raw = $profile->new('uint8_t[64]');

        $id = $profile->new('int64_t[1]');
        $id[0] = 77;
        $this->assertSame(self::DECLINE, self::ffiCall($profile, 'nntmux_profile_lookup_params', '1', 1, 'n', 1, null));
        $this->assertSame(self::DECLINE, self::ffiCall($profile, 'nntmux_profile_lookup_params', '1', 1, 'n', 1, $profile->cast('int64_t*', FFI::addr($raw[1]))));
        $this->assertSame(self::DECLINE, self::ffiCall($profile, 'nntmux_profile_lookup_params', null, 1, 'n', 1, $id));
        $this->assertSame(self::DECLINE, self::ffiCall($profile, 'nntmux_profile_lookup_params', '1', -1, 'n', 1, $id));
        $this->assertSame(77, $id[0]);
        $this->assertSame(ProfileController::LOOKUP_BY_ID, self::ffiCall($profile, 'nntmux_profile_lookup_params', '42', 2, null, 0, $id));
        $this->assertSame(42, $id[0]);
        $this->assertSame(self::DECLINE, self::ffiCall($profile, 'nntmux_profile_theme_allowed', 2, 'dark', 4));
        $this->assertSame(self::DECLINE, self::ffiCall($profile, 'nntmux_profile_theme_allowed', 0, null, 4));

        $lens = $profile->new('size_t[2]');
        $lens[0] = 1;
        $lens[1] = 1;
        $kinds = $profile->new('uint8_t[2]');
        $kinds[0] = 9;
        $values = $profile->new('int64_t[2]');
        $this->assertSame(-2, self::ffiCall($profile, 'nntmux_profile_numeric_classify', '12', 2, $lens, 2, $kinds, $values, 1));
        $this->assertSame(-2, self::ffiCall($profile, 'nntmux_profile_numeric_classify', '12', 2, $lens, 2, null, null, 0));
        $this->assertSame(self::DECLINE, self::ffiCall($profile, 'nntmux_profile_numeric_classify', '12', 3, $lens, 2, $kinds, $values, 2));
        $this->assertSame(self::DECLINE, self::ffiCall($profile, 'nntmux_profile_numeric_classify', '12', 2, null, 2, $kinds, $values, 2));
        $this->assertSame(self::DECLINE, self::ffiCall($profile, 'nntmux_profile_numeric_classify', '12', 2, $profile->cast('size_t*', FFI::addr($raw[1])), 2, $kinds, $values, 2));
        $this->assertSame(self::DECLINE, self::ffiCall($profile, 'nntmux_profile_numeric_classify', '12', 2, $lens, 2, $kinds, $profile->cast('int64_t*', FFI::addr($raw[1])), 2));
        $this->assertSame(self::DECLINE, self::ffiCall($profile, 'nntmux_profile_numeric_classify', '12', 2, $lens, 2, $kinds, $values, -1));
        $this->assertSame(9, $kinds[0]);
        $this->assertSame(2, self::ffiCall($profile, 'nntmux_profile_numeric_classify', '12', 2, $lens, 2, $kinds, $values, 2));
        $this->assertSame([1, 1, 1, 2], [$kinds[0], $kinds[1], $values[0], $values[1]]);

        $out = $profile->new('char[64]');
        $out[0] = 'Z';
        $this->assertSame(-16, self::ffiCall($profile, 'nntmux_profile_cache_key', 0, 42, $out, 4));
        $this->assertSame(-16, self::ffiCall($profile, 'nntmux_profile_cache_key', 0, 42, null, 0));
        $this->assertSame(self::DECLINE, self::ffiCall($profile, 'nntmux_profile_cache_key', 2, 42, $out, 64));
        $this->assertSame(self::DECLINE, self::ffiCall($profile, 'nntmux_profile_cache_key', 0, 42, null, 64));
        $this->assertSame(self::DECLINE, self::ffiCall($profile, 'nntmux_profile_cache_key', 0, 42, $out, -1));
        $this->assertSame('Z', $out[0]);

        $this->assertSame(self::DECLINE, self::ffiCall($password, 'nntmux_password_security_profile_referer', 2, 0, '', 0));
        $this->assertSame(self::DECLINE, self::ffiCall($password, 'nntmux_password_security_profile_referer', 0, 1, null, 3));
        $cookie = $password->new('char[128]');
        $cookie[0] = 'Z';
        $this->assertSame(-40, self::ffiCall($password, 'nntmux_password_security_trusted_cookie', 1, 't', 1, 1, $cookie, 8));
        $this->assertSame(-40, self::ffiCall($password, 'nntmux_password_security_trusted_cookie', 1, 't', 1, 1, null, 0));
        $this->assertSame(self::DECLINE, self::ffiCall($password, 'nntmux_password_security_trusted_cookie', 1, 't', 1, 1, null, 128));
        $this->assertSame(self::DECLINE, self::ffiCall($password, 'nntmux_password_security_trusted_cookie', 1, null, 1, 1, $cookie, 128));
        $this->assertSame(self::JSON_INVALID_UTF8, self::ffiCall($password, 'nntmux_password_security_trusted_cookie', 1, "\xff", 1, 1, $cookie, 128));
        $this->assertSame('Z', $cookie[0]);
        $this->assertSame(self::DECLINE, self::ffiCall($password, 'nntmux_password_security_can_cancel', 2, 0));

        $meta = $security->new('int32_t[3]');
        $meta[0] = 77;
        $stateLens = $security->new('size_t[2]');
        $message = $security->new('char[128]');
        $this->assertSame(-46, self::ffiCall($security, 'nntmux_profile_security_disable2fa_state', 1, 1, 0, $meta, $stateLens, $message, 10));
        $this->assertSame(-46, self::ffiCall($security, 'nntmux_profile_security_disable2fa_state', 1, 1, 0, $meta, $stateLens, null, 0));
        $this->assertSame(self::DECLINE, self::ffiCall($security, 'nntmux_profile_security_disable2fa_state', 2, 1, 0, $meta, $stateLens, $message, 128));
        $this->assertSame(self::DECLINE, self::ffiCall($security, 'nntmux_profile_security_disable2fa_state', 1, 1, 0, null, $stateLens, $message, 128));
        $this->assertSame(self::DECLINE, self::ffiCall($security, 'nntmux_profile_security_disable2fa_state', 1, 1, 0, $security->cast('int32_t*', FFI::addr($raw[1])), $stateLens, $message, 128));
        $this->assertSame(self::DECLINE, self::ffiCall($security, 'nntmux_profile_security_disable2fa_state', 1, 1, 0, $meta, null, $message, 128));
        $this->assertSame(self::DECLINE, self::ffiCall($security, 'nntmux_profile_security_disable2fa_state', 1, 1, 0, $meta, $stateLens, $message, -1));
        $this->assertSame(77, $meta[0]);

        $payloadLens = $privacy->new('size_t[2]');
        $payload = $privacy->new('char[64]');
        $this->assertSame(-17, self::ffiCall($privacy, 'nntmux_privacy_center_request_payload', 1, 0, 'ERASE', 5, $payloadLens, $payload, 16));
        $this->assertSame(self::DECLINE, self::ffiCall($privacy, 'nntmux_privacy_center_request_payload', 2, 0, '', 0, $payloadLens, $payload, 64));
        $this->assertSame(self::DECLINE, self::ffiCall($privacy, 'nntmux_privacy_center_request_payload', 1, 1, '', 0, $payloadLens, $payload, 64));
        $this->assertSame(self::DECLINE, self::ffiCall($privacy, 'nntmux_privacy_center_request_payload', 1, 0, null, 5, $payloadLens, $payload, 64));
        $this->assertSame(self::DECLINE, self::ffiCall($privacy, 'nntmux_privacy_center_request_payload', 1, 0, 'E', 1, null, $payload, 64));
        $this->assertSame(self::DECLINE, self::ffiCall($privacy, 'nntmux_privacy_center_can_download', 1, 1, 2));
        $this->assertSame(-19, self::ffiCall($privacy, 'nntmux_privacy_center_export_filename', 42, $payload, 4));
        $this->assertSame(self::DECLINE, self::ffiCall($privacy, 'nntmux_privacy_center_export_filename', 42, null, 64));

        $available = $invitation->new('int64_t[1]');
        $available[0] = 77;
        $this->assertSame(self::DECLINE, self::ffiCall($invitation, 'nntmux_invitation_available', PHP_INT_MIN, 1, $available));
        $this->assertSame(self::DECLINE, self::ffiCall($invitation, 'nntmux_invitation_available', 1, 0, null));
        $this->assertSame(self::DECLINE, self::ffiCall($invitation, 'nntmux_invitation_available', 1, 0, $invitation->cast('int64_t*', FFI::addr($raw[1]))));
        $this->assertSame(77, $available[0]);
        $this->assertSame(self::DECLINE, self::ffiCall($invitation, 'nntmux_invitation_row_plan', 2, 0));
        $keyLens = $invitation->new('size_t[2]');
        $keyLens[0] = 1;
        $keyLens[1] = 2;
        $this->assertSame(1, self::ffiCall($invitation, 'nntmux_invitation_role_index', '02', 2, '102', 3, $keyLens, 2));
        $this->assertSame(-1, self::ffiCall($invitation, 'nntmux_invitation_role_index', '2', 1, '102', 3, $keyLens, 2));
        $this->assertSame(self::DECLINE, self::ffiCall($invitation, 'nntmux_invitation_role_index', '1', 1, '102', 2, $keyLens, 2));
        $this->assertSame(self::DECLINE, self::ffiCall($invitation, 'nntmux_invitation_role_index', '1', 1, '102', 3, null, 2));
        $this->assertSame(self::DECLINE, self::ffiCall($invitation, 'nntmux_invitation_role_index', null, 1, '102', 3, $keyLens, 2));
    }

    public function test_native_methods_fall_back_to_php_when_ffi_unavailable(): void
    {
        foreach (self::TOGGLED_CLASSES as $class) {
            $class::setFfiForTesting(false);
            $class::setUseRustFfi(true);
        }

        $this->assertSame(['mode' => ProfileController::LOOKUP_BY_ID, 'id' => 42], ProfileController::profileLookupParameters('42', 'bob'));
        $this->assertTrue(ProfileController::isAllowedThemeValue(ProfileController::COLOR_SCHEME, 'amber'));
        $this->assertSame([0 => 5010, 2 => 2], ProfileController::excludedCategoryIds(['5010', 'x', '2.5']));
        $this->assertSame('composer_user_7', ProfileController::cacheKey(ProfileController::CACHE_COMPOSER_USER, 7));
        $this->assertTrue(PasswordSecurityController::isProfileReferer(false, 'https://x/profileedit'));
        $this->assertSame('{"user_id":1,"token":"t/","expires_at":2}', PasswordSecurityController::trustedDeviceCookiePayload(1, 't/', 2));
        $this->assertNull(PasswordSecurityController::trustedDeviceCookiePayloadNative(1, "\xff", 2));
        $this->assertTrue(PasswordSecurityController::canCancelSetup(true, false));
        $this->assertSame('error_2fa', ProfileSecurityController::disable2faResponseState(true, false, true)['flash_key']);
        $this->assertSame(['requested_by' => 'self_service', 'ip_recorded' => true], PrivacyCenterController::exportRequestPayload(true));
        $this->assertSame(['requested_by' => 'self_service', 'confirmation' => 'ERASE'], PrivacyCenterController::erasureRequestPayloadNative('ERASE'));
        $this->assertFalse(PrivacyCenterController::canDownloadExport(1, 2, true));
        $this->assertSame('gdpr-export-9.json', PrivacyCenterController::exportDownloadFilename(9));
        $this->assertSame(['available' => 3, 'can_send_invites' => true], InvitationController::availableInvites(5, 2));
        $this->assertSame(['created_at' => 1, 'expires_at' => 2, 'used_at' => 3], InvitationController::normalizeInvitationRow([], null, 1, 2, 3));
        $this->assertSame('Admin', InvitationController::previewRoleName('2', [2 => 'Admin']));
    }

    // ---------------------------------------------------------------------
    // Paired Randomized Benchmarks
    // ---------------------------------------------------------------------

    public function test_paired_randomized_benchmarks(): void
    {
        if (getenv('NNTMUX_PROFILE_SECURITY_PRIVACY_INVITE_BENCH') !== '1') {
            $this->markTestSkipped('Set NNTMUX_PROFILE_SECURITY_PRIVACY_INVITE_BENCH=1 to run paired Rust/PHP benchmarks.');
        }
        $this->useLibraryUnderTest();

        $excludedCommon = ['2030', '2040', '5030'];
        $excludedUncommon = [];
        foreach (range(1000, 1400) as $i) {
            $excludedUncommon[] = match ($i % 4) {
                0 => (string) $i,
                1 => " {$i} ",
                2 => "{$i}.5",
                default => "cat-{$i}",
            };
        }
        $token = str_repeat('aB3dE5fG', 8);
        $tokenUncommon = str_repeat('tökén/"😀', 40);
        $row = ['id' => 3, 'email' => 'friend@example.com', 'token' => $token, 'created_at' => '2026-10-01T00:00:00.000000Z', 'expires_at' => '2026-10-08T00:00:00.000000Z', 'used_at' => null, 'is_active' => true, 'metadata' => ['role' => '2']];
        $roles = [1 => 'User', 2 => 'Admin', 3 => 'Disabled', 4 => 'Moderator', 5 => 'Friend'];
        $rolesUncommon = [];
        foreach (range(1, 200) as $i) {
            $rolesUncommon["role-{$i}"] = "Role {$i}";
        }

        $workloads = [
            'ProfileController::profileLookupParameters' => [
                ProfileController::class,
                static fn () => ProfileController::profileLookupParameters('', ''),
                static fn () => ProfileController::profileLookupParameters('99999999999999999999999', 'alice'),
            ],
            'ProfileController::isAllowedThemeValue' => [
                ProfileController::class,
                static fn () => ProfileController::isAllowedThemeValue(ProfileController::THEME_PREFERENCE, 'dark'),
                static fn () => ProfileController::isAllowedThemeValue(ProfileController::COLOR_SCHEME, 'not-a-scheme'),
            ],
            'ProfileController::excludedCategoryIds' => [
                ProfileController::class,
                static fn () => ProfileController::excludedCategoryIds($excludedCommon),
                static fn () => ProfileController::excludedCategoryIds($excludedUncommon),
            ],
            'ProfileController::cacheKey' => [
                ProfileController::class,
                static fn () => ProfileController::cacheKey(ProfileController::CACHE_COMPOSER_USER, 12345),
                static fn () => ProfileController::cacheKey(ProfileController::CACHE_CATEGORY_EXCLUSIONS, PHP_INT_MIN),
            ],
            'PasswordSecurityController::isProfileReferer' => [
                PasswordSecurityController::class,
                static fn () => PasswordSecurityController::isProfileReferer(false, 'https://nntmux.test/profileedit'),
                static fn () => PasswordSecurityController::isProfileReferer(false, str_repeat('https://nntmux.test/a/', 100)),
            ],
            'PasswordSecurityController::trustedDeviceCookiePayload' => [
                PasswordSecurityController::class,
                static fn () => PasswordSecurityController::trustedDeviceCookiePayload(12345, $token, 1_800_000_000),
                static fn () => PasswordSecurityController::trustedDeviceCookiePayload(PHP_INT_MAX, $tokenUncommon, PHP_INT_MIN),
            ],
            'PasswordSecurityController::canCancelSetup' => [
                PasswordSecurityController::class,
                static fn () => PasswordSecurityController::canCancelSetup(true, false),
                static fn () => PasswordSecurityController::canCancelSetup(false, true),
            ],
            'ProfileSecurityController::disable2faResponseState' => [
                ProfileSecurityController::class,
                static fn () => ProfileSecurityController::disable2faResponseState(true, true, false),
                static fn () => ProfileSecurityController::disable2faResponseState(true, false, true),
            ],
            'PrivacyCenterController::exportRequestPayload' => [
                PrivacyCenterController::class,
                static fn () => PrivacyCenterController::exportRequestPayload(false),
                static fn () => PrivacyCenterController::exportRequestPayload(true),
            ],
            'PrivacyCenterController::erasureRequestPayload' => [
                PrivacyCenterController::class,
                static fn () => PrivacyCenterController::erasureRequestPayload('ERASE'),
                static fn () => PrivacyCenterController::erasureRequestPayload(str_repeat('ERASE', 400)),
            ],
            'PrivacyCenterController::canDownloadExport' => [
                PrivacyCenterController::class,
                static fn () => PrivacyCenterController::canDownloadExport(12345, 12345, true),
                static fn () => PrivacyCenterController::canDownloadExport(12345, 54321, false),
            ],
            'PrivacyCenterController::exportDownloadFilename' => [
                PrivacyCenterController::class,
                static fn () => PrivacyCenterController::exportDownloadFilename(12345),
                static fn () => PrivacyCenterController::exportDownloadFilename(PHP_INT_MIN),
            ],
            'InvitationController::availableInvites' => [
                InvitationController::class,
                static fn () => InvitationController::availableInvites(5, 2),
                static fn () => InvitationController::availableInvites(0, 3),
            ],
            'InvitationController::normalizeInvitationRow' => [
                InvitationController::class,
                static fn () => InvitationController::normalizeInvitationRow($row, null, 1_759_000_000, 1_759_600_000, null),
                static fn () => InvitationController::normalizeInvitationRow($row, ['id' => 9, 'username' => 'bob'], 1_759_000_000, 1_759_600_000, 1_759_300_000),
            ],
            'InvitationController::previewRoleName' => [
                InvitationController::class,
                static fn () => InvitationController::previewRoleName('2', $roles),
                static fn () => InvitationController::previewRoleName('role-200', $rolesUncommon),
            ],
        ];

        $rows = [];
        foreach ($workloads as $name => [$class, $common, $uncommon]) {
            $worst = ['common_mean' => INF, 'common_p99' => INF, 'uncommon_mean' => INF, 'uncommon_p99' => INF];
            for ($rep = 0; $rep < 3; $rep++) {
                foreach (['common' => $common, 'uncommon' => $uncommon] as $kind => $fn) {
                    $class::setUseRustFfi(true);
                    $rustOut = self::invoke($fn);
                    $class::setUseRustFfi(false);
                    $this->assertSame(self::invoke($fn), $rustOut, "{$name} {$kind} benchmark output parity");

                    $stats = $this->runPairedBenchmark($class, $fn, 200, 41, 2805 + $rep);
                    fwrite(STDERR, json_encode(['method' => $name, 'workload' => $kind, 'repetition' => $rep] + $stats).PHP_EOL);
                    $worst["{$kind}_mean"] = min($worst["{$kind}_mean"], $stats['mean_speedup']);
                    $worst["{$kind}_p99"] = min($worst["{$kind}_p99"], $stats['p99_speedup']);
                }
            }
            $class::setUseRustFfi(false);
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
        }
        $this->resetFfiState();
        foreach (self::TOGGLED_CLASSES as $class) {
            $this->assertFalse($class::usesRustFfi(), "{$class} must keep the PHP default");
        }

        fwrite(STDERR, implode(PHP_EOL, $rows).PHP_EOL);
    }

    private static function invoke(Closure $fn): mixed
    {
        return $fn();
    }

    /**
     * @param  class-string  $class
     * @return array{rust_mean_us: float, php_mean_us: float, rust_p99_us: float, php_p99_us: float, mean_speedup: float, p99_speedup: float, samples: int, inner_loops: int}
     */
    private function runPairedBenchmark(string $class, Closure $fn, int $innerLoops, int $samples, int $seed): array
    {
        foreach ([true, false] as $use) {
            $class::setUseRustFfi($use);
            for ($w = 0; $w < 50; $w++) {
                $fn();
            }
        }

        $times = [1 => [], 0 => []];
        mt_srand($seed);
        for ($s = 0; $s < $samples; $s++) {
            $order = mt_rand(0, 1) === 0 ? [true, false] : [false, true];
            foreach ($order as $use) {
                $class::setUseRustFfi($use);
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
        ];
    }
}
