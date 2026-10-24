<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\IncidentImpactEnum;
use App\Enums\IncidentStatusEnum;
use App\Enums\ServiceStatusEnum;
use App\Http\Requests\Admin\AdminPromotionRequest;
use App\Http\Requests\Admin\UpdateIncidentRequest;
use App\Http\Requests\Admin\UpdateServiceHealthRequest;
use App\Http\Requests\UpdateProfileRequest;
use App\Http\Requests\UpdateThemeRequest;
use App\Rules\ValidEmailDomain;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Validation\Rule;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Paired randomized benchmark evidence (`NNTMUX_PROFILE_THEME_ADMIN_REQUEST_BENCH=1`,
 * `NNTMUX_PIPELINE_FFI_LIB=/tmp/cargo-w25-3/release/libnntmux_pipeline.dylib`, release profile,
 * 3 rounds x 61 paired samples x 200 iterations of all 5 helpers, warmup=8, seeded random
 * order (mt_srand(253)), Apple Silicon Darwin, PHP 8.5.12; p99 is the 61st-of-61 sample):
 *
 * - Round 1: PHP median=0.183 ms p99=0.448 ms, Rust median=1.032 ms p99=1.706 ms (0.18x)
 * - Round 2: PHP median=0.178 ms p99=0.312 ms, Rust median=1.009 ms p99=1.850 ms (0.18x)
 * - Round 3: PHP median=0.178 ms p99=0.199 ms, Rust median=1.005 ms p99=1.326 ms (0.18x)
 * - Two further runs measured 0.13x-0.18x median speedups with Rust p99 also slower.
 * - Overall: per-call FFI marshaling dominates these constant/short-list rule builders, so PHP
 *   stays the production default (`$useRustFfi = false`) for all 5 FormRequest classes
 *   because the 1.5x threshold is not met.
 */
class RustProfileThemeAdminRequestParityTest extends TestCase
{
    private const DIRECT_CDEF = <<<'CDEF'
    ssize_t nntmux_profile_rule_spec(
        uint8_t user_kind,
        int64_t user_int,
        const char *user_ptr,
        size_t user_len,
        size_t *out_lens,
        char *out_buf,
        size_t out_cap
    );
    ssize_t nntmux_theme_rule_spec(
        size_t *out_lens,
        char *out_buf,
        size_t out_cap
    );
    ssize_t nntmux_admin_promotion_rules(
        size_t *out_pair_count,
        char *out_buf,
        size_t out_cap
    );
    ssize_t nntmux_update_incident_rule_spec(
        const char *impact_ptr,
        size_t impact_len,
        size_t impact_count,
        const char *status_ptr,
        size_t status_len,
        size_t status_count,
        size_t *out_meta,
        char *out_buf,
        size_t out_cap
    );
    ssize_t nntmux_service_health_status_values(
        const char *status_ptr,
        size_t status_len,
        size_t status_count,
        size_t *out_count,
        char *out_buf,
        size_t out_cap
    );
    CDEF;

    private const PASSWORD_REGEX_RULE = 'regex:/^(?=.*?[A-Z])(?=.*?[a-z])(?=.*?[0-9])(?=.*?[#?!@$%^&*-]).{8,}$/';

    /**
     * @var list<class-string>
     */
    private const CLASSES = [
        UpdateProfileRequest::class,
        UpdateThemeRequest::class,
        AdminPromotionRequest::class,
        UpdateIncidentRequest::class,
        UpdateServiceHealthRequest::class,
    ];

    protected function tearDown(): void
    {
        foreach (self::CLASSES as $class) {
            $class::resetRustFfi();
        }

        parent::tearDown();
    }

    public function test_production_defaults_remain_php_and_toggles_work(): void
    {
        foreach (self::CLASSES as $class) {
            $class::resetRustFfi();
            $this->assertFalse($class::usesRustFfi(), "{$class} must default to the PHP implementation");

            $class::setUseRustFfi(true);
            $this->assertTrue($class::usesRustFfi());

            $class::setUseRustFfi(false);
            $this->assertFalse($class::usesRustFfi());

            $class::enableRustFfi();
            $this->assertTrue($class::usesRustFfi());

            $class::disableRustFfi();
            $this->assertFalse($class::usesRustFfi());
        }
    }

    /**
     * @return array<string, array{0: mixed, 1: string}>
     */
    public static function profileUserIdProvider(): array
    {
        return [
            'guest_null' => [null, 'unique:users,email,'],
            'int_id' => [42, 'unique:users,email,42'],
            'zero_id' => [0, 'unique:users,email,0'],
            'negative_id' => [-7, 'unique:users,email,-7'],
            'int_max' => [PHP_INT_MAX, 'unique:users,email,'.PHP_INT_MAX],
            'int_min' => [PHP_INT_MIN, 'unique:users,email,'.PHP_INT_MIN],
            'numeric_string' => ['17', 'unique:users,email,17'],
            'empty_string' => ['', 'unique:users,email,'],
            'utf8_string' => ['ünï-🙂', 'unique:users,email,ünï-🙂'],
            'embedded_nul_string' => ["9\0x", "unique:users,email,9\0x"],
            'float_declines_to_php' => [1.5, 'unique:users,email,1.5'],
            'bool_declines_to_php' => [true, 'unique:users,email,1'],
        ];
    }

    #[DataProvider('profileUserIdProvider')]
    public function test_profile_rule_spec_and_rules_parity(mixed $userId, string $expectedUnique): void
    {
        $this->injectFfi();

        $php = UpdateProfileRequest::profileRuleSpecPhp($userId);
        $this->assertSame($expectedUnique, $php['email_unique_rule']);
        $this->assertSame($php, UpdateProfileRequest::profileRuleSpecNative($userId));

        foreach ([false, true] as $useRust) {
            UpdateProfileRequest::setUseRustFfi($useRust);
            $this->assertSame($php, UpdateProfileRequest::profileRuleSpec($userId));
        }

        $expectedRules = [
            'email' => ['nullable', 'string', 'email', 'max:255', $expectedUnique, new ValidEmailDomain],
            'password' => ['nullable', 'string', 'min:8', 'confirmed', self::PASSWORD_REGEX_RULE],
            'theme_preference' => ['sometimes', 'in:light,dark,system'],
            'color_scheme' => ['sometimes', 'in:blue,indigo,cyan,teal,emerald,violet,pink,rose,red,orange,amber'],
        ];

        foreach ([false, true] as $useRust) {
            UpdateProfileRequest::setUseRustFfi($useRust);
            $request = UpdateProfileRequest::create('/profile', 'POST');
            $request->setUserResolver(static fn () => $userId === null ? null : (object) ['id' => $userId]);
            $this->assertEquals($expectedRules, $request->rules());
        }
    }

    public function test_theme_rule_spec_and_rules_parity(): void
    {
        $this->injectFfi();

        $php = UpdateThemeRequest::themeRuleSpecPhp();
        $this->assertSame($php, UpdateThemeRequest::themeRuleSpecNative());

        $expected = [
            'theme_preference' => ['sometimes', 'in:light,dark,system'],
            'color_scheme' => ['sometimes', 'in:blue,indigo,cyan,teal,emerald,violet,pink,rose,red,orange,amber'],
        ];
        foreach ([false, true] as $useRust) {
            UpdateThemeRequest::setUseRustFfi($useRust);
            $this->assertSame($php, UpdateThemeRequest::themeRuleSpec());
            $this->assertSame($expected, UpdateThemeRequest::create('/theme', 'POST')->rules());
        }
    }

    public function test_admin_promotion_rules_parity_preserves_order(): void
    {
        $this->injectFfi();

        $expected = [
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'applicable_roles' => 'nullable|array',
            'applicable_roles.*' => 'exists:roles,id',
            'additional_days' => 'required|integer|min:0',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'is_active' => 'boolean',
        ];

        $this->assertSame($expected, AdminPromotionRequest::promotionRulesPhp());
        $this->assertSame($expected, AdminPromotionRequest::promotionRulesNative());
        foreach ([false, true] as $useRust) {
            AdminPromotionRequest::setUseRustFfi($useRust);
            $this->assertSame($expected, AdminPromotionRequest::promotionRules());
            $this->assertSame($expected, AdminPromotionRequest::create('/admin/promotions', 'POST')->rules());
        }
    }

    /**
     * @return array<string, array{0: array<array-key, string>, 1: array<array-key, string>}>
     */
    public static function incidentValuesProvider(): array
    {
        return [
            'default_enums' => [IncidentImpactEnum::values(), IncidentStatusEnum::values()],
            'whitespace_duplicates_and_empty' => [
                ['  none ', 'minor', 'minor ', '', " \t\n ", 'major', "critical\x0B"],
                [' investigating ', 'identified', '', 'monitoring', 'identified', "resolved\r\n"],
            ],
            'keyed_arrays' => [['a' => 'none', 'b' => 'minor'], [5 => 'resolved']],
            'numeric_strings' => [['1', '01', ' 1', '1.0'], ['0', '00']],
            'single_empty_value' => [[''], ['resolved']],
            'empty_lists' => [[], []],
            'utf8_values' => [[' ünï ', 'ünï', '日本'], ['🙂']],
            'embedded_nul_declines_to_php' => [["none\0extra", 'minor'], ['resolved']],
        ];
    }

    /**
     * @param  array<array-key, string>  $impact
     * @param  array<array-key, string>  $status
     */
    #[DataProvider('incidentValuesProvider')]
    public function test_update_incident_rule_spec_parity(array $impact, array $status): void
    {
        $this->injectFfi();

        $php = UpdateIncidentRequest::incidentRuleSpecPhp($impact, $status);
        $this->assertSame($php, UpdateIncidentRequest::incidentRuleSpecNative($impact, $status));
        foreach ([false, true] as $useRust) {
            UpdateIncidentRequest::setUseRustFfi($useRust);
            $this->assertSame($php, UpdateIncidentRequest::incidentRuleSpec($impact, $status));
        }
    }

    public function test_update_incident_rules_and_authorization_are_preserved(): void
    {
        $this->injectFfi();

        $expected = [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string'],
            'service_status_ids' => ['required', 'array', 'min:1'],
            'service_status_ids.*' => ['integer', 'distinct', 'exists:service_statuses,id'],
            'impact' => ['required', 'string', Rule::in(IncidentImpactEnum::values())],
            'status' => ['required', 'string', Rule::in(IncidentStatusEnum::values())],
            'started_at' => ['required', 'date'],
            'resolved_at' => ['nullable', 'date', 'after_or_equal:started_at'],
        ];

        foreach ([false, true] as $useRust) {
            UpdateIncidentRequest::setUseRustFfi($useRust);
            $request = UpdateIncidentRequest::create('/admin/incidents/1', 'PUT');
            $this->assertTrue($request->authorize());
            $rules = $request->rules();
            $this->assertEquals($expected, $rules);
            $this->assertSame((string) $expected['impact'][2], (string) $rules['impact'][2]);
            $this->assertSame((string) $expected['status'][2], (string) $rules['status'][2]);
        }
    }

    /**
     * @return array<string, array{0: array<array-key, string>}>
     */
    public static function serviceStatusProvider(): array
    {
        return [
            'default_enum' => [ServiceStatusEnum::values()],
            'whitespace_and_duplicates' => [[' operational', 'degraded', 'degraded ', '', "\tmaintenance\0"]],
            'numeric_strings' => [['1', '01', '1']],
            'empty' => [[]],
            'single_empty' => [['']],
            'embedded_nul_declines_to_php' => [["major\0outage"]],
        ];
    }

    /**
     * @param  array<array-key, string>  $values
     */
    #[DataProvider('serviceStatusProvider')]
    public function test_service_health_status_values_parity(array $values): void
    {
        $this->injectFfi();

        $php = UpdateServiceHealthRequest::statusRuleValuesPhp($values);
        $this->assertSame($php, UpdateServiceHealthRequest::statusRuleValuesNative($values));
        foreach ([false, true] as $useRust) {
            UpdateServiceHealthRequest::setUseRustFfi($useRust);
            $this->assertSame($php, UpdateServiceHealthRequest::statusRuleValues($values));
        }
    }

    public function test_service_health_rules_and_authorization_are_preserved(): void
    {
        $this->injectFfi();

        $expected = ['status' => ['required', 'string', Rule::in(ServiceStatusEnum::values())]];
        foreach ([false, true] as $useRust) {
            UpdateServiceHealthRequest::setUseRustFfi($useRust);
            $request = UpdateServiceHealthRequest::create('/admin/service-health/1', 'PUT');
            $this->assertTrue($request->authorize());
            $rules = $request->rules();
            $this->assertEquals($expected, $rules);
            $this->assertSame((string) $expected['status'][2], (string) $rules['status'][2]);
        }
    }

    public function test_all_five_requests_fall_back_cleanly_when_ffi_is_unavailable(): void
    {
        foreach (self::CLASSES as $class) {
            $class::setFfiForTesting(false);
            $class::enableRustFfi();
        }

        $this->assertSame(UpdateProfileRequest::profileRuleSpecPhp(42), UpdateProfileRequest::profileRuleSpec(42));
        $this->assertSame(UpdateThemeRequest::themeRuleSpecPhp(), UpdateThemeRequest::themeRuleSpec());
        $this->assertSame(AdminPromotionRequest::promotionRulesPhp(), AdminPromotionRequest::promotionRules());
        $this->assertSame(
            UpdateIncidentRequest::incidentRuleSpecPhp(IncidentImpactEnum::values(), IncidentStatusEnum::values()),
            UpdateIncidentRequest::incidentRuleSpec(IncidentImpactEnum::values(), IncidentStatusEnum::values())
        );
        $this->assertSame(
            UpdateServiceHealthRequest::statusRuleValuesPhp(ServiceStatusEnum::values()),
            UpdateServiceHealthRequest::statusRuleValues(ServiceStatusEnum::values())
        );
    }

    public function test_direct_c_abi_rejects_null_unaligned_invalid_and_undersized_buffers(): void
    {
        $ffi = $this->requireFfi();

        $raw = $ffi->new('uint8_t[64]');
        $unaligned = $ffi->cast('size_t *', FFI::addr($raw[1]));
        $lens = $ffi->new('size_t[4]');
        $meta = $ffi->new('size_t[4]');
        $count = $ffi->new('size_t[1]');
        $buf = $ffi->new('char[512]');
        $small = $ffi->new('char[4]');
        for ($i = 0; $i < 4; $i++) {
            $small[$i] = 'Z';
        }

        // Profile: invalid kind, null string, null/unaligned lens, null out_buf, oversized cap.
        $this->assertSame(-1, self::callFfi($ffi, 'nntmux_profile_rule_spec', 3, 0, '', 0, $lens, $buf, 512));
        $this->assertSame(-1, self::callFfi($ffi, 'nntmux_profile_rule_spec', 2, 0, null, 0, $lens, $buf, 512));
        $this->assertSame(-1, self::callFfi($ffi, 'nntmux_profile_rule_spec', 1, 1, '', 0, null, $buf, 512));
        $this->assertSame(-1, self::callFfi($ffi, 'nntmux_profile_rule_spec', 1, 1, '', 0, $unaligned, $buf, 512));
        $this->assertSame(-1, self::callFfi($ffi, 'nntmux_profile_rule_spec', 1, 1, '', 0, $lens, null, 512));
        $this->assertSame(-1, self::callFfi($ffi, 'nntmux_profile_rule_spec', 1, 1, '', 0, $lens, $buf, -1));
        $full = self::callFfi($ffi, 'nntmux_profile_rule_spec', 1, 42, '', 0, $lens, $buf, 512);
        $this->assertGreaterThan(0, $full);
        $lens[0] = 999;
        $this->assertSame(-$full, self::callFfi($ffi, 'nntmux_profile_rule_spec', 1, 42, '', 0, $lens, $small, 4));
        $this->assertSame(999, (int) $lens[0]);
        $this->assertSame('ZZZZ', FFI::string($small, 4));

        // Theme.
        $this->assertSame(-1, self::callFfi($ffi, 'nntmux_theme_rule_spec', null, $buf, 512));
        $this->assertSame(-1, self::callFfi($ffi, 'nntmux_theme_rule_spec', $unaligned, $buf, 512));
        $theme = self::callFfi($ffi, 'nntmux_theme_rule_spec', $lens, $buf, 512);
        $this->assertSame(-$theme, self::callFfi($ffi, 'nntmux_theme_rule_spec', $lens, $small, 4));
        $this->assertSame('ZZZZ', FFI::string($small, 4));

        // Promotion.
        $this->assertSame(-1, self::callFfi($ffi, 'nntmux_admin_promotion_rules', null, $buf, 512));
        $this->assertSame(-1, self::callFfi($ffi, 'nntmux_admin_promotion_rules', $unaligned, $buf, 512));
        $promo = self::callFfi($ffi, 'nntmux_admin_promotion_rules', $count, $buf, 512);
        $this->assertSame(8, (int) $count[0]);
        $count[0] = 77;
        $this->assertSame(-$promo, self::callFfi($ffi, 'nntmux_admin_promotion_rules', $count, $small, 4));
        $this->assertSame(77, (int) $count[0]);
        $this->assertSame('ZZZZ', FFI::string($small, 4));

        // Incident: null input, count mismatch, unaligned meta, undersized output.
        $this->assertSame(-1, self::callFfi($ffi, 'nntmux_update_incident_rule_spec', null, 0, 0, '', 0, 0, $meta, $buf, 512));
        $this->assertSame(-1, self::callFfi($ffi, 'nntmux_update_incident_rule_spec', "a\0b", 3, 3, '', 0, 0, $meta, $buf, 512));
        $this->assertSame(-1, self::callFfi($ffi, 'nntmux_update_incident_rule_spec', '', 0, 2, '', 0, 0, $meta, $buf, 512));
        $this->assertSame(-1, self::callFfi($ffi, 'nntmux_update_incident_rule_spec', 'a', 1, 1, '', 0, 0, $unaligned, $buf, 512));
        $meta[0] = 55;
        $this->assertSame(-11, self::callFfi($ffi, 'nntmux_update_incident_rule_spec', "major\0minor", 11, 2, '', 0, 0, $meta, $small, 4));
        $this->assertSame(55, (int) $meta[0]);
        $this->assertSame('ZZZZ', FFI::string($small, 4));

        // Service health.
        $this->assertSame(-1, self::callFfi($ffi, 'nntmux_service_health_status_values', null, 0, 0, $count, $buf, 512));
        $this->assertSame(-1, self::callFfi($ffi, 'nntmux_service_health_status_values', 'a', 1, 2, $count, $buf, 512));
        $this->assertSame(-1, self::callFfi($ffi, 'nntmux_service_health_status_values', 'a', 1, 1, $unaligned, $buf, 512));
        $this->assertSame(-1, self::callFfi($ffi, 'nntmux_service_health_status_values', 'a', 1, 1, $count, null, 512));
        // A one-byte requirement reports -2 so it never collides with the decline code.
        $this->assertSame(-2, self::callFfi($ffi, 'nntmux_service_health_status_values', 'a', 1, 1, $count, $small, 0));
        $this->assertSame('ZZZZ', FFI::string($small, 4));
    }

    public function test_paired_randomized_benchmark(): void
    {
        if ((string) getenv('NNTMUX_PROFILE_THEME_ADMIN_REQUEST_BENCH') !== '1') {
            $this->markTestSkipped('Set NNTMUX_PROFILE_THEME_ADMIN_REQUEST_BENCH=1 to run the paired benchmark.');
        }

        $this->injectFfi();

        $impacts = IncidentImpactEnum::values();
        $statuses = IncidentStatusEnum::values();
        $serviceStatuses = ServiceStatusEnum::values();

        $runBatch = static function (int $iterations) use ($impacts, $statuses, $serviceStatuses): void {
            for ($i = 0; $i < $iterations; $i++) {
                UpdateProfileRequest::profileRuleSpec(1000 + $i);
                UpdateThemeRequest::themeRuleSpec();
                AdminPromotionRequest::promotionRules();
                UpdateIncidentRequest::incidentRuleSpec($impacts, $statuses);
                UpdateServiceHealthRequest::statusRuleValues($serviceStatuses);
            }
        };
        $setAllModes = static function (bool $rust): void {
            foreach (self::CLASSES as $class) {
                $class::setUseRustFfi($rust);
            }
        };
        $measure = static function (bool $rust, int $iterations) use ($setAllModes, $runBatch): float {
            $setAllModes($rust);
            $t0 = hrtime(true);
            $runBatch($iterations);

            return (hrtime(true) - $t0) / 1_000_000;
        };

        for ($w = 0; $w < 8; $w++) {
            $measure(false, 10);
            $measure(true, 10);
        }

        mt_srand(253);
        $rounds = 3;
        $samples = 61;
        $iterations = 200;
        $summary = [];

        for ($round = 1; $round <= $rounds; $round++) {
            $phpTimes = [];
            $rustTimes = [];
            for ($s = 0; $s < $samples; $s++) {
                if (mt_rand(0, 1) === 1) {
                    $rustTimes[] = $measure(true, $iterations);
                    $phpTimes[] = $measure(false, $iterations);
                } else {
                    $phpTimes[] = $measure(false, $iterations);
                    $rustTimes[] = $measure(true, $iterations);
                }
            }

            sort($phpTimes);
            sort($rustTimes);
            $p99Index = (int) ceil(0.99 * $samples) - 1;
            $phpMedian = $phpTimes[intdiv($samples, 2)];
            $rustMedian = $rustTimes[intdiv($samples, 2)];
            $summary[] = sprintf(
                'Round %d: PHP median=%.3f ms p99=%.3f ms, Rust median=%.3f ms p99=%.3f ms, speedup=%.2fx',
                $round,
                $phpMedian,
                $phpTimes[$p99Index],
                $rustMedian,
                $rustTimes[$p99Index],
                $phpMedian / max(0.0001, $rustMedian)
            );
        }

        fwrite(STDERR, "\n[Wave 25.3 Benchmark] ".implode(' | ', $summary)."\n");
        $this->assertCount($rounds, $summary);
        foreach (self::CLASSES as $class) {
            $class::resetRustFfi();
            $this->assertFalse($class::usesRustFfi());
        }
    }

    private static function callFfi(FFI $ffi, string $symbol, mixed ...$args): mixed
    {
        return $ffi->$symbol(...$args);
    }

    private function injectFfi(): void
    {
        $ffi = $this->requireFfi();
        foreach (self::CLASSES as $class) {
            $class::setFfiForTesting($ffi);
        }
    }

    private function requireFfi(): FFI
    {
        $path = getenv('NNTMUX_PIPELINE_FFI_LIB');
        $ffi = RustPipelineLibrary::resolveDomain(
            'categorize',
            self::DIRECT_CDEF,
            is_string($path) && $path !== '' ? $path : null
        );
        if ($ffi === null) {
            $this->markTestSkipped('Rust categorize FFI library is not built or available.');
        }

        return $ffi;
    }
}
