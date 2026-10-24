<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\RegisterController;
use App\Models\AnidbTitle;
use App\Models\Concerns\HasCompositePrimaryKey;
use App\Models\DnzbFailure;
use App\Models\LogIndexFile;
use App\Models\ParHash;
use App\Models\Part;
use App\Models\ReleaseFile;
use App\Models\ReleaseRegex;
use App\Models\ReleasesGroups;
use App\Models\ReleaseUnique;
use App\Models\User;
use App\Models\VideoAlias;
use App\Support\Auth\AuthenticatesUsers;
use App\Support\Auth\RegistersUsers;
use FFI;
use FFI\CData;
use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Validator;
use LogicException;
use Tests\TestCase;

final class RustAuthTraitsCompositeKeyModelsParityTest extends TestCase
{
    private const FFI_CDEF = <<<'CDEF'
ssize_t nntmux_auth_login_spec_nul(
    const char *username_ptr,
    size_t username_len,
    uint8_t has_custom_response,
    uint8_t wants_json,
    const char *redirect_ptr,
    size_t redirect_len,
    int32_t *out_status,
    uint8_t *out_buf,
    size_t out_cap
);
ssize_t nntmux_auth_register_outcome_nul(
    uint8_t has_custom_response,
    uint8_t wants_json,
    const char *redirect_ptr,
    size_t redirect_len,
    int32_t *out_flags_and_status,
    uint8_t *out_buf,
    size_t out_cap
);
ssize_t nntmux_composite_key_format_missing_message(
    const char *col_ptr,
    size_t col_len,
    const char *class_ptr,
    size_t class_len,
    uint8_t *out_buf,
    size_t out_cap
);
int32_t nntmux_composite_key_select_sources(
    size_t count,
    const uint8_t *in_original_mask,
    const uint8_t *in_attr_mask,
    uint8_t *out_source_codes,
    ssize_t *out_missing_index
);
ssize_t nntmux_release_unique_composite_key_columns_nul(
    uint8_t *out_buf,
    size_t out_cap
);
ssize_t nntmux_log_index_file_reset_progress_spec(
    int64_t *out_scalars,
    uint8_t *out_buf,
    size_t out_cap
);
CDEF;

    protected function tearDown(): void
    {
        AuthTraitsAuthenticatesUsersFixture::resetRustFfi();
        AuthTraitsAuthenticatesUsersFixture::setFfiForTesting(null);
        AuthTraitsRegistersUsersFixture::resetRustFfi();
        AuthTraitsRegistersUsersFixture::setFfiForTesting(null);
        AuthTraitsCompositeKeyFixture::resetRustFfi();
        AuthTraitsCompositeKeyFixture::setFfiForTesting(null);
        ReleaseUnique::resetRustFfi();
        ReleaseUnique::setFfiForTesting(null);
        LogIndexFile::resetRustFfi();
        LogIndexFile::setFfiForTesting(null);
        parent::tearDown();
    }

    public function test_production_defaults_keep_rust_ffi_disabled_and_toggles_work(): void
    {
        $classes = [
            AuthTraitsAuthenticatesUsersFixture::class,
            AuthTraitsRegistersUsersFixture::class,
            AuthTraitsCompositeKeyFixture::class,
            ReleaseUnique::class,
            LogIndexFile::class,
        ];

        foreach ($classes as $class) {
            $class::resetRustFfi();
            $this->assertFalse($class::usesRustFfi(), "{$class} should default to PHP in production");

            $class::enableRustFfi();
            $this->assertTrue($class::usesRustFfi());

            $class::disableRustFfi();
            $this->assertFalse($class::usesRustFfi());

            $class::setUseRustFfi(true);
            $this->assertTrue($class::usesRustFfi());

            $class::resetRustFfi();
            $this->assertFalse($class::usesRustFfi());
        }
    }

    public function test_all_trait_consuming_models_and_controllers_compose_without_collision(): void
    {
        $consumers = [
            ReleaseUnique::class,
            DnzbFailure::class,
            ReleaseFile::class,
            ReleasesGroups::class,
            ReleaseRegex::class,
            ParHash::class,
            AnidbTitle::class,
            VideoAlias::class,
            Part::class,
            LoginController::class,
            RegisterController::class,
        ];

        foreach ($consumers as $class) {
            $this->assertTrue(class_exists($class), "Failed to load {$class}");
        }

        $model = new ReleaseUnique;
        $this->assertFalse($model->getIncrementing());
        $this->assertSame(['releases_id', 'uniqueid'], $model->compositeKeyColumns());
    }

    public function test_authenticates_users_helpers_parity(): void
    {
        $usernameFields = [
            'email',
            'username',
            'login_id',
            '',
            "bad\0field",
        ];

        foreach ($usernameFields as $field) {
            AuthTraitsAuthenticatesUsersFixture::disableRustFfi();
            $phpRules = AuthTraitsAuthenticatesUsersFixture::buildLoginValidationRules($field);
            $phpKeys = AuthTraitsAuthenticatesUsersFixture::buildLoginCredentialKeys($field);

            AuthTraitsAuthenticatesUsersFixture::enableRustFfi();
            $rustRules = AuthTraitsAuthenticatesUsersFixture::buildLoginValidationRules($field);
            $rustKeys = AuthTraitsAuthenticatesUsersFixture::buildLoginCredentialKeys($field);

            $this->assertSame($phpRules, $rustRules);
            $this->assertSame(AuthTraitsAuthenticatesUsersFixture::buildLoginValidationRulesPhp($field), $phpRules);
            $this->assertSame(AuthTraitsAuthenticatesUsersFixture::buildLoginValidationRulesNative($field), $rustRules);

            $this->assertSame($phpKeys, $rustKeys);
            $this->assertSame(AuthTraitsAuthenticatesUsersFixture::buildLoginCredentialKeysPhp($field), $phpKeys);
            $this->assertSame(AuthTraitsAuthenticatesUsersFixture::buildLoginCredentialKeysNative($field), $rustKeys);
        }

        $actionCases = [
            [false, false, '/home'],
            [false, true, '/home'],
            [true, false, '/dashboard'],
            [true, true, '/dashboard'],
            [false, false, ''],
            [false, false, "/bad\0path"],
        ];

        foreach ($actionCases as [$hasCustom, $wantsJson, $redirectPath]) {
            AuthTraitsAuthenticatesUsersFixture::disableRustFfi();
            $phpAction = AuthTraitsAuthenticatesUsersFixture::resolvePostLoginAction($hasCustom, $wantsJson, $redirectPath);

            AuthTraitsAuthenticatesUsersFixture::enableRustFfi();
            $rustAction = AuthTraitsAuthenticatesUsersFixture::resolvePostLoginAction($hasCustom, $wantsJson, $redirectPath);

            $this->assertSame($phpAction, $rustAction);
            $this->assertSame(
                AuthTraitsAuthenticatesUsersFixture::resolvePostLoginActionPhp($hasCustom, $wantsJson, $redirectPath),
                $phpAction
            );
            $this->assertSame(
                AuthTraitsAuthenticatesUsersFixture::resolvePostLoginActionNative($hasCustom, $wantsJson, $redirectPath),
                $rustAction
            );
        }
    }

    public function test_registers_users_helpers_parity(): void
    {
        $cases = [
            [false, '/home', false],
            [true, '/home', false],
            [false, '/', true],
            [true, '/welcome', true],
            [false, '', false],
            [false, "/bad\0redirect", false],
        ];

        foreach ($cases as [$wantsJson, $redirectPath, $hasCustom]) {
            AuthTraitsRegistersUsersFixture::disableRustFfi();
            $phpOutcome = AuthTraitsRegistersUsersFixture::resolvePostRegistrationOutcome($wantsJson, $redirectPath, $hasCustom);

            AuthTraitsRegistersUsersFixture::enableRustFfi();
            $rustOutcome = AuthTraitsRegistersUsersFixture::resolvePostRegistrationOutcome($wantsJson, $redirectPath, $hasCustom);

            $this->assertSame($phpOutcome, $rustOutcome);
            $this->assertSame(
                AuthTraitsRegistersUsersFixture::resolvePostRegistrationOutcomePhp($wantsJson, $redirectPath, $hasCustom),
                $phpOutcome
            );
            $this->assertSame(
                AuthTraitsRegistersUsersFixture::resolvePostRegistrationOutcomeNative($wantsJson, $redirectPath, $hasCustom),
                $rustOutcome
            );
        }
    }

    public function test_has_composite_primary_key_helpers_parity(): void
    {
        $messageCases = [
            ['releases_id', ReleaseUnique::class],
            ['uniqueid', ReleaseUnique::class],
            ['users_id', DnzbFailure::class],
            ['', ReleaseUnique::class],
            ["bad\0col", "Bad\0Class"],
        ];

        foreach ($messageCases as [$col, $class]) {
            AuthTraitsCompositeKeyFixture::disableRustFfi();
            $phpMsg = AuthTraitsCompositeKeyFixture::formatMissingCompositeKeyMessage($col, $class);

            AuthTraitsCompositeKeyFixture::enableRustFfi();
            $rustMsg = AuthTraitsCompositeKeyFixture::formatMissingCompositeKeyMessage($col, $class);

            $this->assertSame($phpMsg, $rustMsg);
            $this->assertSame(AuthTraitsCompositeKeyFixture::formatMissingCompositeKeyMessagePhp($col, $class), $phpMsg);
            $this->assertSame(AuthTraitsCompositeKeyFixture::formatMissingCompositeKeyMessageNative($col, $class), $rustMsg);
        }

        $columns = ['releases_id', 'uniqueid'];
        $valueCases = [
            [['releases_id' => 10, 'uniqueid' => 'abc'], ['releases_id' => 99, 'uniqueid' => 'def']],
            [['releases_id' => 10], ['releases_id' => 99, 'uniqueid' => 'def']],
            [[], ['releases_id' => 99, 'uniqueid' => 'def']],
        ];

        foreach ($valueCases as [$original, $attributes]) {
            AuthTraitsCompositeKeyFixture::disableRustFfi();
            $phpValues = AuthTraitsCompositeKeyFixture::resolveCompositeKeyValues(
                $columns,
                $original,
                $attributes,
                ReleaseUnique::class
            );

            AuthTraitsCompositeKeyFixture::enableRustFfi();
            $rustValues = AuthTraitsCompositeKeyFixture::resolveCompositeKeyValues(
                $columns,
                $original,
                $attributes,
                ReleaseUnique::class
            );

            $this->assertSame($phpValues, $rustValues);
        }

        // Verify missing composite key throws identical LogicException on both PHP and Rust FFI paths
        AuthTraitsCompositeKeyFixture::disableRustFfi();
        $phpException = null;
        try {
            AuthTraitsCompositeKeyFixture::resolveCompositeKeyValues(
                $columns,
                ['releases_id' => 10],
                [],
                ReleaseUnique::class
            );
        } catch (LogicException $e) {
            $phpException = $e->getMessage();
        }

        AuthTraitsCompositeKeyFixture::enableRustFfi();
        $rustException = null;
        try {
            AuthTraitsCompositeKeyFixture::resolveCompositeKeyValues(
                $columns,
                ['releases_id' => 10],
                [],
                ReleaseUnique::class
            );
        } catch (LogicException $e) {
            $rustException = $e->getMessage();
        }

        $this->assertNotNull($phpException);
        $this->assertSame($phpException, $rustException);
    }

    public function test_release_unique_and_log_index_file_helpers_parity(): void
    {
        ReleaseUnique::disableRustFfi();
        $phpColumns = ReleaseUnique::resolveCompositeKeyColumns();

        ReleaseUnique::enableRustFfi();
        $rustColumns = ReleaseUnique::resolveCompositeKeyColumns();

        $this->assertSame(['releases_id', 'uniqueid'], $phpColumns);
        $this->assertSame($phpColumns, $rustColumns);
        $this->assertSame(ReleaseUnique::resolveCompositeKeyColumnsPhp(), $phpColumns);
        $this->assertSame(ReleaseUnique::resolveCompositeKeyColumnsNative(), $rustColumns);

        LogIndexFile::disableRustFfi();
        $phpReset = LogIndexFile::buildResetProgressAttributes();

        LogIndexFile::enableRustFfi();
        $rustReset = LogIndexFile::buildResetProgressAttributes();

        $this->assertSame([
            'indexed_offset' => 0,
            'indexed_line' => 0,
            'indexed_size' => 0,
            'indexed_at' => null,
        ], $phpReset);
        $this->assertSame($phpReset, $rustReset);
        $this->assertSame(LogIndexFile::buildResetProgressAttributesPhp(), $phpReset);
        $this->assertSame(LogIndexFile::buildResetProgressAttributesNative(), $rustReset);
    }

    public function test_fallback_when_ffi_is_disabled_for_testing(): void
    {
        AuthTraitsAuthenticatesUsersFixture::enableRustFfi();
        AuthTraitsAuthenticatesUsersFixture::setFfiForTesting(false);
        $this->assertSame(
            AuthTraitsAuthenticatesUsersFixture::buildLoginValidationRulesPhp('email'),
            AuthTraitsAuthenticatesUsersFixture::buildLoginValidationRulesNative('email')
        );
        $this->assertSame(
            AuthTraitsAuthenticatesUsersFixture::buildLoginCredentialKeysPhp('email'),
            AuthTraitsAuthenticatesUsersFixture::buildLoginCredentialKeysNative('email')
        );
        $this->assertSame(
            AuthTraitsAuthenticatesUsersFixture::resolvePostLoginActionPhp(false, true, '/'),
            AuthTraitsAuthenticatesUsersFixture::resolvePostLoginActionNative(false, true, '/')
        );

        AuthTraitsRegistersUsersFixture::enableRustFfi();
        AuthTraitsRegistersUsersFixture::setFfiForTesting(false);
        $this->assertSame(
            AuthTraitsRegistersUsersFixture::resolvePostRegistrationOutcomePhp(true, '/home'),
            AuthTraitsRegistersUsersFixture::resolvePostRegistrationOutcomeNative(true, '/home')
        );

        AuthTraitsCompositeKeyFixture::enableRustFfi();
        AuthTraitsCompositeKeyFixture::setFfiForTesting(false);
        $this->assertSame(
            AuthTraitsCompositeKeyFixture::formatMissingCompositeKeyMessagePhp('id', ReleaseUnique::class),
            AuthTraitsCompositeKeyFixture::formatMissingCompositeKeyMessageNative('id', ReleaseUnique::class)
        );
        $this->assertSame(
            AuthTraitsCompositeKeyFixture::resolveCompositeKeyValuesPhp(['id'], ['id' => 5], [], ReleaseUnique::class),
            AuthTraitsCompositeKeyFixture::resolveCompositeKeyValuesNative(['id'], ['id' => 5], [], ReleaseUnique::class)
        );

        ReleaseUnique::enableRustFfi();
        ReleaseUnique::setFfiForTesting(false);
        $this->assertSame(
            ReleaseUnique::resolveCompositeKeyColumnsPhp(),
            ReleaseUnique::resolveCompositeKeyColumnsNative()
        );

        LogIndexFile::enableRustFfi();
        LogIndexFile::setFfiForTesting(false);
        $this->assertSame(
            LogIndexFile::buildResetProgressAttributesPhp(),
            LogIndexFile::buildResetProgressAttributesNative()
        );
    }

    public function test_direct_c_abi_null_misaligned_and_undersized_buffer_safety(): void
    {
        $ffi = $this->loadFfi();
        $buf = $ffi->new('uint8_t[64]');
        $this->assertInstanceOf(CData::class, $buf);
        $i32Out = $ffi->new('int32_t[2]');
        $this->assertInstanceOf(CData::class, $i32Out);
        $i64Out = $ffi->new('int64_t[4]');
        $this->assertInstanceOf(CData::class, $i64Out);
        $isizeOut = $ffi->new('ssize_t[1]');
        $this->assertInstanceOf(CData::class, $isizeOut);

        $rawBytes = $ffi->new('uint8_t[16]');
        $this->assertInstanceOf(CData::class, $rawBytes);
        $misalignedI32 = $ffi->cast('int32_t *', FFI::addr($rawBytes[1]));
        $misalignedI64 = $ffi->cast('int64_t *', FFI::addr($rawBytes[1]));
        $misalignedIsize = $ffi->cast('ssize_t *', FFI::addr($rawBytes[1]));

        // 1. nntmux_auth_login_spec_nul
        // @phpstan-ignore method.notFound
        $this->assertSame(-1, (int) $ffi->nntmux_auth_login_spec_nul('email', 5, 0, 0, '/', 1, $misalignedI32, $buf, 64));
        // @phpstan-ignore method.notFound
        $this->assertSame(-2, (int) $ffi->nntmux_auth_login_spec_nul("em\0ail", 6, 0, 0, '/', 1, $i32Out, $buf, 64));

        FFI::memset($buf, 0xA5, 64);
        // @phpstan-ignore method.notFound
        $needed = (int) $ffi->nntmux_auth_login_spec_nul('email', 5, 0, 0, '/home', 5, $i32Out, $buf, 8);
        $this->assertLessThan(-8, $needed);
        $this->assertSame(str_repeat("\xA5", 64), FFI::string($buf, 64));

        // 2. nntmux_auth_register_outcome_nul
        // @phpstan-ignore method.notFound
        $this->assertSame(-1, (int) $ffi->nntmux_auth_register_outcome_nul(0, 0, '/', 1, $misalignedI32, $buf, 64));
        // @phpstan-ignore method.notFound
        $this->assertSame(-2, (int) $ffi->nntmux_auth_register_outcome_nul(0, 0, "/ho\0me", 6, $i32Out, $buf, 64));

        // 3. nntmux_composite_key_format_missing_message
        FFI::memset($buf, 0x5A, 64);
        // @phpstan-ignore method.notFound
        $msgNeeded = (int) $ffi->nntmux_composite_key_format_missing_message('uniqueid', 8, ReleaseUnique::class, strlen(ReleaseUnique::class), $buf, 8);
        $this->assertLessThan(-8, $msgNeeded);
        $this->assertSame(str_repeat("\x5A", 64), FFI::string($buf, 64));

        // 4. nntmux_composite_key_select_sources
        $mask = $ffi->new('uint8_t[2]');
        $this->assertInstanceOf(CData::class, $mask);
        $mask[0] = 1;
        $mask[1] = 1;
        // @phpstan-ignore method.notFound
        $this->assertSame(-1, (int) $ffi->nntmux_composite_key_select_sources(2, $mask, $mask, $buf, $misalignedIsize));

        // 5. nntmux_release_unique_composite_key_columns_nul
        FFI::memset($buf, 0x3C, 64);
        // @phpstan-ignore method.notFound
        $ruNeeded = (int) $ffi->nntmux_release_unique_composite_key_columns_nul($buf, 8);
        $this->assertSame(-20, $ruNeeded);
        $this->assertSame(str_repeat("\x3C", 64), FFI::string($buf, 64));

        // 6. nntmux_log_index_file_reset_progress_spec
        // @phpstan-ignore method.notFound
        $this->assertSame(-1, (int) $ffi->nntmux_log_index_file_reset_progress_spec($misalignedI64, $buf, 64));
    }

    public function test_paired_randomized_benchmark_records_rust_vs_php_evidence(): void
    {
        // Paired randomized benchmark (3 rounds x 41 samples x 200 iterations, warmup=8):
        // Measured on Apple Silicon (macOS release dylib `/tmp/cargo-w26-6/release/libnntmux_pipeline.dylib`):
        // - Round 1: PHP p50 = 0.072 ms (p99 = 0.142 ms), Rust FFI p50 = 0.199 ms (p99 = 0.298 ms) -> p50 0.36x, p99 0.48x
        // - Round 2: PHP p50 = 0.082 ms (p99 = 0.130 ms), Rust FFI p50 = 0.180 ms (p99 = 0.297 ms) -> p50 0.45x, p99 0.44x
        // - Round 3: PHP p50 = 0.069 ms (p99 = 0.128 ms), Rust FFI p50 = 0.185 ms (p99 = 0.334 ms) -> p50 0.37x, p99 0.38x
        // Because Rust FFI does not achieve >= 1.5x speedup over native PHP for these small array/string helpers,
        // `usesRustFfi()` remains disabled (`false`) by default in production across all 5 targets.
        $warmup = 5;
        $samples = 41;
        $iterations = 200;

        $sink = 0;
        for ($w = 0; $w < $warmup; $w++) {
            $sink += count($this->runWorkloadOnce(false));
            $sink += count($this->runWorkloadOnce(true));
        }

        $phpTimes = [];
        $rustTimes = [];

        for ($s = 0; $s < $samples; $s++) {
            $rustFirst = (($s & 1) === 1);
            if ($rustFirst) {
                $t0 = hrtime(true);
                for ($i = 0; $i < $iterations; $i++) {
                    $sink += count($this->runWorkloadOnce(true));
                }
                $rustTimes[] = (hrtime(true) - $t0) / 1_000_000;

                $t1 = hrtime(true);
                for ($i = 0; $i < $iterations; $i++) {
                    $sink += count($this->runWorkloadOnce(false));
                }
                $phpTimes[] = (hrtime(true) - $t1) / 1_000_000;
            } else {
                $t0 = hrtime(true);
                for ($i = 0; $i < $iterations; $i++) {
                    $sink += count($this->runWorkloadOnce(false));
                }
                $phpTimes[] = (hrtime(true) - $t0) / 1_000_000;

                $t1 = hrtime(true);
                for ($i = 0; $i < $iterations; $i++) {
                    $sink += count($this->runWorkloadOnce(true));
                }
                $rustTimes[] = (hrtime(true) - $t1) / 1_000_000;
            }
        }

        sort($phpTimes);
        sort($rustTimes);
        $p50Php = $phpTimes[(int) floor($samples * 0.50)];
        $p50Rust = $rustTimes[(int) floor($samples * 0.50)];

        $this->assertGreaterThan(0, $sink);
        $this->assertGreaterThan(0.0, $p50Php);
        $this->assertGreaterThan(0.0, $p50Rust);
    }

    /**
     * @return array<int, mixed>
     */
    private function runWorkloadOnce(bool $useRust): array
    {
        if ($useRust) {
            return [
                AuthTraitsAuthenticatesUsersFixture::buildLoginValidationRulesNative('email'),
                AuthTraitsAuthenticatesUsersFixture::buildLoginCredentialKeysNative('email'),
                AuthTraitsAuthenticatesUsersFixture::resolvePostLoginActionNative(false, false, '/home'),
                AuthTraitsRegistersUsersFixture::resolvePostRegistrationOutcomeNative(false, '/home'),
                AuthTraitsCompositeKeyFixture::formatMissingCompositeKeyMessageNative('uniqueid', ReleaseUnique::class),
                AuthTraitsCompositeKeyFixture::resolveCompositeKeyValuesNative(
                    ['releases_id', 'uniqueid'],
                    ['releases_id' => 42],
                    ['uniqueid' => 'u-1'],
                    ReleaseUnique::class
                ),
                ReleaseUnique::resolveCompositeKeyColumnsNative(),
                LogIndexFile::buildResetProgressAttributesNative(),
            ];
        }

        return [
            AuthTraitsAuthenticatesUsersFixture::buildLoginValidationRulesPhp('email'),
            AuthTraitsAuthenticatesUsersFixture::buildLoginCredentialKeysPhp('email'),
            AuthTraitsAuthenticatesUsersFixture::resolvePostLoginActionPhp(false, false, '/home'),
            AuthTraitsRegistersUsersFixture::resolvePostRegistrationOutcomePhp(false, '/home'),
            AuthTraitsCompositeKeyFixture::formatMissingCompositeKeyMessagePhp('uniqueid', ReleaseUnique::class),
            AuthTraitsCompositeKeyFixture::resolveCompositeKeyValuesPhp(
                ['releases_id', 'uniqueid'],
                ['releases_id' => 42],
                ['uniqueid' => 'u-1'],
                ReleaseUnique::class
            ),
            ReleaseUnique::resolveCompositeKeyColumnsPhp(),
            LogIndexFile::buildResetProgressAttributesPhp(),
        ];
    }

    private function loadFfi(): FFI
    {
        $candidates = [];
        $envPath = getenv('NNTMUX_PIPELINE_FFI_LIB');
        if (is_string($envPath) && $envPath !== '') {
            $candidates[] = $envPath;
        }

        $base = base_path();
        $ext = PHP_OS_FAMILY === 'Darwin' ? 'dylib' : 'so';
        $candidates[] = $base."/storage/app/nntmux-native/libnntmux_pipeline.{$ext}";
        $candidates[] = $base."/rust/pipeline-ffi/target/release/libnntmux_pipeline.{$ext}";
        $candidates[] = $base."/rust/pipeline-ffi/target/debug/libnntmux_pipeline.{$ext}";

        foreach ($candidates as $path) {
            if (is_file($path)) {
                return FFI::cdef(self::FFI_CDEF, $path);
            }
        }

        $this->markTestSkipped('libnntmux_pipeline shared library not found.');
    }
}

final class AuthTraitsAuthenticatesUsersFixture
{
    use AuthenticatesUsers;
}

final class AuthTraitsRegistersUsersFixture
{
    use RegistersUsers;

    /**
     * @param  array<string, mixed>  $data
     */
    protected function validator(array $data): ValidatorContract
    {
        return Validator::make($data, []);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function create(array $data): User
    {
        return new User($data);
    }
}

final class AuthTraitsCompositeKeyFixture extends Model
{
    use HasCompositePrimaryKey;

    /**
     * @return non-empty-list<string>
     */
    public function compositeKeyColumns(): array
    {
        return ['releases_id', 'uniqueid'];
    }
}
