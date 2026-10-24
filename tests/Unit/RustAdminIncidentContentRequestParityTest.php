<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\IncidentImpactEnum;
use App\Enums\IncidentStatusEnum;
use App\Enums\RegistrationStatus;
use App\Http\Requests\Admin\AdminContentRequest;
use App\Http\Requests\Admin\RegistrationPeriodRequest;
use App\Http\Requests\Admin\StoreIncidentRequest;
use App\Http\Requests\Admin\UpdateRegistrationStatusRequest;
use App\Http\Requests\ContactContactURequest;
use App\Models\RegistrationPeriod;
use App\Support\RustPipelineLibrary;
use FFI;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Paired randomized benchmark evidence (`NNTMUX_ADMIN_INCIDENT_CONTENT_REQUEST_BENCH=1`,
 * 3 rounds x 41 samples x 200 iterations, warmup=8, Apple Silicon Darwin release dylib):
 *
 * - Round 1: PHP median = 0.201 ms, Rust FFI median = 0.956 ms (0.21x; per-call FFI marshaling overhead dominates sub-microsecond scalar/array helpers)
 * - Round 2: PHP median = 0.203 ms, Rust FFI median = 0.953 ms (0.21x)
 * - Round 3: PHP median = 0.203 ms, Rust FFI median = 0.953 ms (0.21x)
 * - Overall: PHP stays the production default (`$useRustFfi = false`) across all 5 FormRequest classes because the 1.5x speedup threshold is not met.
 */
class RustAdminIncidentContentRequestParityTest extends TestCase
{
    private const DIRECT_CDEF = <<<'CDEF'
    ssize_t nntmux_admin_content_normalize_inputs(
        uint32_t string_mask,
        const char *action_ptr,
        size_t action_len,
        const char *title_ptr,
        size_t title_len,
        const char *url_ptr,
        size_t url_len,
        const char *desc_ptr,
        size_t desc_len,
        const char *kw_ptr,
        size_t kw_len,
        size_t *out_lens,
        char *out_buf,
        size_t out_cap
    );
    int32_t nntmux_admin_content_is_submit_action(
        const char *method_ptr,
        size_t method_len,
        uint32_t action_is_str,
        const char *action_ptr,
        size_t action_len
    );
    int32_t nntmux_registration_period_resolve_route_id(
        int32_t kind,
        int64_t int_val,
        const char *str_ptr,
        size_t str_len,
        int32_t *out_has_id,
        int64_t *out_id
    );
    int32_t nntmux_registration_period_intervals_overlap(
        int64_t start_a,
        int64_t end_a,
        int64_t start_b,
        int64_t end_b
    );
    ssize_t nntmux_store_incident_validation_spec(
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
    ssize_t nntmux_update_registration_status_in_rule(
        const int64_t *values_ptr,
        size_t values_len,
        char *out_buf,
        size_t out_cap
    );
    ssize_t nntmux_contact_request_build_rules(
        uint32_t captcha_enabled,
        const char *extra_keys_ptr,
        size_t extra_keys_len,
        const char *extra_vals_ptr,
        size_t extra_vals_len,
        size_t extra_count,
        size_t *out_pair_count,
        char *out_buf,
        size_t out_cap
    );
    ssize_t nntmux_contact_request_validation_messages(
        size_t *out_pair_count,
        char *out_buf,
        size_t out_cap
    );
    CDEF;

    protected function tearDown(): void
    {
        AdminContentRequest::resetRustFfi();
        RegistrationPeriodRequest::resetRustFfi();
        StoreIncidentRequest::resetRustFfi();
        UpdateRegistrationStatusRequest::resetRustFfi();
        ContactContactURequest::resetRustFfi();

        parent::tearDown();
    }

    public function test_production_defaults_remain_php_and_toggles_work(): void
    {
        $classes = [
            AdminContentRequest::class,
            RegistrationPeriodRequest::class,
            StoreIncidentRequest::class,
            UpdateRegistrationStatusRequest::class,
            ContactContactURequest::class,
        ];

        foreach ($classes as $class) {
            $class::resetRustFfi();
            $this->assertFalse($class::usesRustFfi(), "{$class} must default to PHP implementation");

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

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function adminContentInputProvider(): array
    {
        return [
            'all_strings_with_whitespace' => [[
                'action' => " \t submit \r\n ",
                'title' => "  Welcome to NNTmux \n",
                'url' => "\t/useful-links/getting-started\0\x0B",
                'metadescription' => '   Usenet indexer guide and FAQ   ',
                'metakeywords' => " usenet, nzb, indexer \t",
            ]],
            'mixed_non_string_and_null_fields' => [[
                'action' => 'submit',
                'title' => null,
                'url' => 404,
                'metadescription' => ['array_val'],
                'metakeywords' => false,
            ]],
            'empty_and_whitespace_only_strings' => [[
                'action' => "   \t\n\r\0\x0B",
                'title' => '',
                'url' => '   ',
                'metadescription' => "\t\t",
                'metakeywords' => "\n\r",
            ]],
            'utf8_multibyte_content' => [[
                'action' => ' submit ',
                'title' => '  Übersicht — NNTmux 日本語  ',
                'url' => ' /de/übersicht ',
                'metadescription' => '  Описание страницы  ',
                'metakeywords' => '  ключевые, слова  ',
            ]],
            'missing_keys' => [[]],
        ];
    }

    /**
     * @param  array<string, mixed>  $inputs
     */
    #[DataProvider('adminContentInputProvider')]
    public function test_admin_content_normalize_input_parity(array $inputs): void
    {
        $this->requireCategorizeFfi();

        $php = AdminContentRequest::normalizeContentInputPhp($inputs);
        $native = AdminContentRequest::normalizeContentInputNative($inputs);
        $this->assertSame($php, $native);

        foreach ([false, true] as $useRust) {
            AdminContentRequest::setUseRustFfi($useRust);
            $this->assertSame($php, AdminContentRequest::normalizeContentInput($inputs));
        }

        $reqPhp = AdminContentRequest::create('/admin/content-add', 'POST', $inputs);
        AdminContentRequest::disableRustFfi();
        $this->invokePrepareForValidation($reqPhp);

        $reqRust = AdminContentRequest::create('/admin/content-add', 'POST', $inputs);
        AdminContentRequest::enableRustFfi();
        $this->invokePrepareForValidation($reqRust);

        $this->assertSame($reqPhp->all(), $reqRust->all());
    }

    /**
     * @return array<string, array{0: string, 1: mixed, 2: bool}>
     */
    public static function adminContentSubmitActionProvider(): array
    {
        return [
            'post_submit_upper' => ['POST', 'submit', true],
            'post_submit_lower' => ['post', 'submit', true],
            'post_submit_mixed' => ['PoSt', 'submit', true],
            'get_submit' => ['GET', 'submit', false],
            'put_submit' => ['PUT', 'submit', false],
            'post_untrimmed_submit' => ['POST', ' submit ', false],
            'post_other_action' => ['POST', 'preview', false],
            'post_null_action' => ['POST', null, false],
            'post_int_action' => ['POST', 1, false],
            'post_array_action' => ['POST', ['submit'], false],
            'empty_method' => ['', 'submit', false],
        ];
    }

    #[DataProvider('adminContentSubmitActionProvider')]
    public function test_admin_content_submit_action_and_rules_parity(string $method, mixed $action, bool $expected): void
    {
        $this->requireCategorizeFfi();

        $php = AdminContentRequest::isSubmitActionPhp($method, $action);
        $native = AdminContentRequest::isSubmitActionNative($method, $action);
        $this->assertSame($expected, $php);
        $this->assertSame($php, $native);

        foreach ([false, true] as $useRust) {
            AdminContentRequest::setUseRustFfi($useRust);
            $this->assertSame($php, AdminContentRequest::isSubmitAction($method, $action));
        }

        foreach ([null, 15] as $id) {
            $payload = ['action' => $action];
            if ($id !== null) {
                $payload['id'] = $id;
            }

            $reqPhp = AdminContentRequest::create('/admin/content-add', $method ?: 'GET', $payload);
            AdminContentRequest::disableRustFfi();
            $rulesPhp = $reqPhp->rules();

            $reqRust = AdminContentRequest::create('/admin/content-add', $method ?: 'GET', $payload);
            AdminContentRequest::enableRustFfi();
            $rulesRust = $reqRust->rules();

            $this->assertEquals($rulesPhp, $rulesRust);
        }
    }

    /**
     * @return array<string, array{0: mixed, 1: ?int}>
     */
    public static function registrationPeriodRouteIdProvider(): array
    {
        $modelWithId = new RegistrationPeriod;
        $modelWithId->id = 73;

        $modelWithoutId = new RegistrationPeriod;

        return [
            'model_with_id' => [$modelWithId, 73],
            'model_without_id' => [$modelWithoutId, null],
            'positive_int' => [42, 42],
            'zero_int' => [0, 0],
            'negative_int' => [-9, -9],
            'numeric_string_plain' => ['128', 128],
            'numeric_string_trimmed' => ["  +512 \t\n", 512],
            'numeric_string_negative' => [' -17 ', -17],
            'numeric_string_decimal_declines_to_php' => ['19.75', 19],
            'numeric_string_scientific_declines_to_php' => ['1e3', 1000],
            'float_value' => [24.9, 24],
            'non_numeric_alpha' => ['period-abc', null],
            'non_numeric_empty' => ['', null],
            'non_numeric_whitespace' => ['   ', null],
            'non_numeric_embedded_nul' => ["42\0extra", null],
            'null_value' => [null, null],
            'bool_true' => [true, null],
            'bool_false' => [false, null],
            'array_value' => [[12], null],
        ];
    }

    #[DataProvider('registrationPeriodRouteIdProvider')]
    public function test_registration_period_resolve_route_id_parity(mixed $period, ?int $expected): void
    {
        $this->requireCategorizeFfi();

        $php = RegistrationPeriodRequest::resolveRoutePeriodIdPhp($period);
        $native = RegistrationPeriodRequest::resolveRoutePeriodIdNative($period);
        $this->assertSame($expected, $php);
        $this->assertSame($php, $native);

        foreach ([false, true] as $useRust) {
            RegistrationPeriodRequest::setUseRustFfi($useRust);
            $this->assertSame($php, RegistrationPeriodRequest::resolveRoutePeriodId($period));
        }
    }

    /**
     * @return array<string, array{0: int, 1: int, 2: int, 3: int, 4: bool}>
     */
    public static function registrationPeriodOverlapProvider(): array
    {
        return [
            'disjoint_before' => [100, 200, 201, 300, false],
            'disjoint_after' => [301, 400, 100, 300, false],
            'touching_at_end' => [100, 200, 200, 300, true],
            'touching_at_start' => [200, 300, 100, 200, true],
            'partial_overlap_left' => [100, 250, 200, 350, true],
            'partial_overlap_right' => [200, 350, 100, 250, true],
            'existing_contains_candidate' => [100, 500, 200, 300, true],
            'candidate_contains_existing' => [200, 300, 100, 500, true],
            'identical_bounds' => [150, 250, 150, 250, true],
            'single_timestamp_match' => [100, 100, 100, 100, true],
        ];
    }

    #[DataProvider('registrationPeriodOverlapProvider')]
    public function test_registration_period_intervals_overlap_parity(
        int $startA,
        int $endA,
        int $startB,
        int $endB,
        bool $expected
    ): void {
        $this->requireCategorizeFfi();

        $php = RegistrationPeriodRequest::intervalsOverlapPhp($startA, $endA, $startB, $endB);
        $native = RegistrationPeriodRequest::intervalsOverlapNative($startA, $endA, $startB, $endB);
        $this->assertSame($expected, $php);
        $this->assertSame($php, $native);

        foreach ([false, true] as $useRust) {
            RegistrationPeriodRequest::setUseRustFfi($useRust);
            $this->assertSame($php, RegistrationPeriodRequest::intervalsOverlap($startA, $endA, $startB, $endB));
        }
    }

    /**
     * @return array<string, array{0: array<array-key, string>, 1: array<array-key, string>}>
     */
    public static function storeIncidentValidationSpecProvider(): array
    {
        return [
            'default_enums' => [
                IncidentImpactEnum::values(),
                IncidentStatusEnum::values(),
            ],
            'whitespace_duplicates_and_empty_entries' => [
                ['  none ', 'minor', 'minor ', '', " \t\n ", 'major', 'critical'],
                [' investigating ', 'identified', '', 'monitoring', 'identified', "resolved\r\n"],
            ],
            'single_values' => [
                ['critical'],
                ['resolved'],
            ],
            'empty_lists' => [
                [],
                [],
            ],
            'embedded_nul_declines_to_php_cleanly' => [
                ["none\0extra", 'minor'],
                ['investigating', 'resolved'],
            ],
        ];
    }

    /**
     * @param  array<array-key, string>  $impactValues
     * @param  array<array-key, string>  $statusValues
     */
    #[DataProvider('storeIncidentValidationSpecProvider')]
    public function test_store_incident_validation_spec_and_rules_parity(array $impactValues, array $statusValues): void
    {
        $this->requireCategorizeFfi();

        $php = StoreIncidentRequest::incidentValidationSpecPhp($impactValues, $statusValues);
        $native = StoreIncidentRequest::incidentValidationSpecNative($impactValues, $statusValues);
        $this->assertSame($php, $native);

        foreach ([false, true] as $useRust) {
            StoreIncidentRequest::setUseRustFfi($useRust);
            $this->assertSame($php, StoreIncidentRequest::incidentValidationSpec($impactValues, $statusValues));
        }

        StoreIncidentRequest::disableRustFfi();
        $rulesPhp = (new StoreIncidentRequest)->rules();

        StoreIncidentRequest::enableRustFfi();
        $rulesRust = (new StoreIncidentRequest)->rules();

        $this->assertEquals($rulesPhp, $rulesRust);
    }

    /**
     * @return array<string, array{0: array<array-key, int>, 1: string}>
     */
    public static function updateRegistrationStatusRuleProvider(): array
    {
        return [
            'default_registration_statuses' => [
                [
                    RegistrationStatus::Open->value,
                    RegistrationStatus::Invite->value,
                    RegistrationStatus::Closed->value,
                ],
                'in:0,1,2',
            ],
            'single_status' => [[1], 'in:1'],
            'negative_and_large_ints' => [[-1, 0, 999999999999], 'in:-1,0,999999999999'],
            'associative_keys' => [['open' => 0, 'closed' => 2], 'in:0,2'],
            'empty_list' => [[], 'in:'],
        ];
    }

    /**
     * @param  array<array-key, int>  $statusValues
     */
    #[DataProvider('updateRegistrationStatusRuleProvider')]
    public function test_update_registration_status_rule_and_rules_parity(array $statusValues, string $expected): void
    {
        $this->requireCategorizeFfi();

        $php = UpdateRegistrationStatusRequest::buildAllowedStatusInRulePhp($statusValues);
        $native = UpdateRegistrationStatusRequest::buildAllowedStatusInRuleNative($statusValues);
        $this->assertSame($expected, $php);
        $this->assertSame($php, $native);

        foreach ([false, true] as $useRust) {
            UpdateRegistrationStatusRequest::setUseRustFfi($useRust);
            $this->assertSame($php, UpdateRegistrationStatusRequest::buildAllowedStatusInRule($statusValues));
        }

        UpdateRegistrationStatusRequest::disableRustFfi();
        $rulesPhp = (new UpdateRegistrationStatusRequest)->rules();

        UpdateRegistrationStatusRequest::enableRustFfi();
        $rulesRust = (new UpdateRegistrationStatusRequest)->rules();

        $this->assertSame($rulesPhp, $rulesRust);
    }

    /**
     * @return array<string, array{0: bool, 1: array<string, mixed>}>
     */
    public static function contactRulesProvider(): array
    {
        return [
            'captcha_disabled_empty_extra' => [false, []],
            'captcha_disabled_ignores_extra' => [false, ['g-recaptcha-response' => 'required|captcha']],
            'captcha_enabled_recaptcha' => [true, ['g-recaptcha-response' => 'required|captcha']],
            'captcha_enabled_turnstile' => [true, ['cf-turnstile-response' => 'required|string']],
            'captcha_enabled_multiple_fields_and_override' => [true, [
                'comment' => 'required|string|min:20',
                'cf-turnstile-response' => 'required|string',
                'captcha_token' => 'required|string|max:2048',
            ]],
            'captcha_enabled_non_string_rule_array' => [true, [
                'g-recaptcha-response' => ['required', 'string'],
            ]],
        ];
    }

    /**
     * @param  array<string, mixed>  $captchaRules
     */
    #[DataProvider('contactRulesProvider')]
    public function test_contact_request_rules_and_messages_parity(bool $captchaEnabled, array $captchaRules): void
    {
        $this->requireCategorizeFfi();

        $phpRules = ContactContactURequest::buildContactRulesPhp($captchaEnabled, $captchaRules);
        $nativeRules = ContactContactURequest::buildContactRulesNative($captchaEnabled, $captchaRules);
        $this->assertSame($phpRules, $nativeRules);

        $phpMessages = ContactContactURequest::contactValidationMessagesPhp();
        $nativeMessages = ContactContactURequest::contactValidationMessagesNative();
        $this->assertSame($phpMessages, $nativeMessages);

        foreach ([false, true] as $useRust) {
            ContactContactURequest::setUseRustFfi($useRust);
            $this->assertSame($phpRules, ContactContactURequest::buildContactRules($captchaEnabled, $captchaRules));
            $this->assertSame($phpMessages, (new ContactContactURequest)->messages());
        }
    }

    public function test_all_five_requests_fall_back_cleanly_when_ffi_is_unavailable(): void
    {
        AdminContentRequest::setFfiForTesting(false);
        AdminContentRequest::enableRustFfi();
        $contentInput = ['action' => ' submit ', 'title' => ' Hi '];
        $this->assertSame(
            AdminContentRequest::normalizeContentInputPhp($contentInput),
            AdminContentRequest::normalizeContentInput($contentInput)
        );
        $this->assertTrue(AdminContentRequest::isSubmitAction('POST', 'submit'));

        RegistrationPeriodRequest::setFfiForTesting(false);
        RegistrationPeriodRequest::enableRustFfi();
        $this->assertSame(42, RegistrationPeriodRequest::resolveRoutePeriodId('42'));
        $this->assertTrue(RegistrationPeriodRequest::intervalsOverlap(10, 20, 15, 25));

        StoreIncidentRequest::setFfiForTesting(false);
        StoreIncidentRequest::enableRustFfi();
        $this->assertSame(
            StoreIncidentRequest::incidentValidationSpecPhp(IncidentImpactEnum::values(), IncidentStatusEnum::values()),
            StoreIncidentRequest::incidentValidationSpec(IncidentImpactEnum::values(), IncidentStatusEnum::values())
        );

        UpdateRegistrationStatusRequest::setFfiForTesting(false);
        UpdateRegistrationStatusRequest::enableRustFfi();
        $this->assertSame('in:0,1,2', UpdateRegistrationStatusRequest::buildAllowedStatusInRule([0, 1, 2]));

        ContactContactURequest::setFfiForTesting(false);
        ContactContactURequest::enableRustFfi();
        $this->assertSame(
            ContactContactURequest::buildContactRulesPhp(true, ['cf-turnstile-response' => 'required']),
            ContactContactURequest::buildContactRules(true, ['cf-turnstile-response' => 'required'])
        );
        $this->assertSame(
            ContactContactURequest::contactValidationMessagesPhp(),
            ContactContactURequest::contactValidationMessages()
        );
    }

    public function test_direct_c_abi_rejects_null_unaligned_invalid_flags_and_undersized_buffers(): void
    {
        $ffi = $this->requireCategorizeFfi();

        $raw = $ffi->new('uint8_t[128]');
        $unalignedSizeT = $ffi->cast('size_t *', FFI::addr($raw[1]));
        $unalignedInt32 = $ffi->cast('int32_t *', FFI::addr($raw[1]));
        $unalignedInt64 = $ffi->cast('int64_t *', FFI::addr($raw[1]));
        $unalignedConstInt64 = $ffi->cast('const int64_t *', FFI::addr($raw[1]));

        $smallBuf = $ffi->new('char[4]');
        for ($i = 0; $i < 4; $i++) {
            $smallBuf[$i] = 'Z';
        }
        $lensSlot = $ffi->new('size_t[5]');
        $metaSlot = $ffi->new('size_t[8]');
        $pairCountSlot = $ffi->new('size_t[1]');
        $pairCountSlot[0] = 777;

        // 1. nntmux_admin_content_normalize_inputs: unaligned out_lens -> -1, invalid mask -> -1, undersized out_cap -> < -1
        $this->assertSame(-1, (int) self::callFfi(
            $ffi,
            'nntmux_admin_content_normalize_inputs',
            1,
            'a',
            1,
            '',
            0,
            '',
            0,
            '',
            0,
            '',
            0,
            $unalignedSizeT,
            $smallBuf,
            4
        ));
        $this->assertSame(-1, (int) self::callFfi(
            $ffi,
            'nntmux_admin_content_normalize_inputs',
            32,
            'a',
            1,
            '',
            0,
            '',
            0,
            '',
            0,
            '',
            0,
            $lensSlot,
            $smallBuf,
            4
        ));
        $rcNormSmall = (int) self::callFfi(
            $ffi,
            'nntmux_admin_content_normalize_inputs',
            1,
            '  submit  ',
            10,
            '',
            0,
            '',
            0,
            '',
            0,
            '',
            0,
            $lensSlot,
            $smallBuf,
            4
        );
        $this->assertLessThan(-1, $rcNormSmall);
        $this->assertSame('ZZZZ', FFI::string($smallBuf, 4));

        // 2. nntmux_admin_content_is_submit_action: invalid action_is_str -> -1, null pointer -> -1
        $this->assertSame(-1, (int) self::callFfi($ffi, 'nntmux_admin_content_is_submit_action', 'POST', 4, 2, 'submit', 6));
        $this->assertSame(-1, (int) self::callFfi($ffi, 'nntmux_admin_content_is_submit_action', null, 4, 1, 'submit', 6));

        // 3. nntmux_registration_period_resolve_route_id: unaligned out pointers & invalid kind -> -1, float string -> -2
        $outHasId = $ffi->new('int32_t[1]');
        $outId = $ffi->new('int64_t[1]');
        $this->assertSame(-1, (int) self::callFfi($ffi, 'nntmux_registration_period_resolve_route_id', 1, 5, '', 0, $unalignedInt32, $outId));
        $this->assertSame(-1, (int) self::callFfi($ffi, 'nntmux_registration_period_resolve_route_id', 1, 5, '', 0, $outHasId, $unalignedInt64));
        $this->assertSame(-1, (int) self::callFfi($ffi, 'nntmux_registration_period_resolve_route_id', 99, 5, '', 0, $outHasId, $outId));
        $this->assertSame(-2, (int) self::callFfi($ffi, 'nntmux_registration_period_resolve_route_id', 2, 0, '12.34', 5, $outHasId, $outId));

        // 4. nntmux_store_incident_validation_spec: unaligned out_meta -> -1, mismatched count -> -1, undersized out_cap -> < -1
        $this->assertSame(-1, (int) self::callFfi(
            $ffi,
            'nntmux_store_incident_validation_spec',
            'none',
            4,
            1,
            'resolved',
            8,
            1,
            $unalignedSizeT,
            $smallBuf,
            4
        ));
        $this->assertSame(-1, (int) self::callFfi(
            $ffi,
            'nntmux_store_incident_validation_spec',
            'none',
            4,
            2,
            'resolved',
            8,
            1,
            $metaSlot,
            $smallBuf,
            4
        ));
        $rcSpecSmall = (int) self::callFfi(
            $ffi,
            'nntmux_store_incident_validation_spec',
            'none',
            4,
            1,
            'resolved',
            8,
            1,
            $metaSlot,
            $smallBuf,
            4
        );
        $this->assertLessThan(-1, $rcSpecSmall);
        $this->assertSame('ZZZZ', FFI::string($smallBuf, 4));

        // 5. nntmux_update_registration_status_in_rule: unaligned values_ptr -> -1, undersized out_cap -> < -1
        $vals = $ffi->new('int64_t[3]');
        $vals[0] = 0;
        $vals[1] = 1;
        $vals[2] = 2;
        $this->assertSame(-1, (int) self::callFfi($ffi, 'nntmux_update_registration_status_in_rule', $unalignedConstInt64, 1, $smallBuf, 4));
        $rcStatusSmall = (int) self::callFfi($ffi, 'nntmux_update_registration_status_in_rule', $vals, 3, $smallBuf, 4);
        $this->assertLessThan(-1, $rcStatusSmall);
        $this->assertSame('ZZZZ', FFI::string($smallBuf, 4));

        // 6. nntmux_contact_request_build_rules & nntmux_contact_request_validation_messages
        $this->assertSame(-1, (int) self::callFfi(
            $ffi,
            'nntmux_contact_request_build_rules',
            2,
            '',
            0,
            '',
            0,
            0,
            $pairCountSlot,
            $smallBuf,
            4
        ));
        $this->assertSame(-1, (int) self::callFfi(
            $ffi,
            'nntmux_contact_request_build_rules',
            0,
            '',
            0,
            '',
            0,
            0,
            $unalignedSizeT,
            $smallBuf,
            4
        ));
        $rcContactSmall = (int) self::callFfi(
            $ffi,
            'nntmux_contact_request_build_rules',
            0,
            '',
            0,
            '',
            0,
            0,
            $pairCountSlot,
            $smallBuf,
            4
        );
        $this->assertLessThan(-1, $rcContactSmall);
        $this->assertSame(777, (int) $pairCountSlot[0]);
        $this->assertSame('ZZZZ', FFI::string($smallBuf, 4));

        $this->assertSame(-1, (int) self::callFfi($ffi, 'nntmux_contact_request_validation_messages', $unalignedSizeT, $smallBuf, 4));
        $rcMsgSmall = (int) self::callFfi($ffi, 'nntmux_contact_request_validation_messages', $pairCountSlot, $smallBuf, 4);
        $this->assertLessThan(-1, $rcMsgSmall);
        $this->assertSame(777, (int) $pairCountSlot[0]);
        $this->assertSame('ZZZZ', FFI::string($smallBuf, 4));
    }

    public function test_paired_randomized_benchmark(): void
    {
        if ((string) getenv('NNTMUX_ADMIN_INCIDENT_CONTENT_REQUEST_BENCH') !== '1') {
            $this->markTestSkipped('Set NNTMUX_ADMIN_INCIDENT_CONTENT_REQUEST_BENCH=1 to run the paired benchmark.');
        }

        $this->requireCategorizeFfi();

        $contentInputs = [
            'action' => '  submit  ',
            'title' => '  Getting Started with NNTmux  ',
            'url' => '  /guides/getting-started  ',
            'metadescription' => '  Step-by-step guide to configuring NNTmux  ',
            'metakeywords' => '  usenet, nzb, guide, setup  ',
        ];
        $impacts = IncidentImpactEnum::values();
        $statuses = IncidentStatusEnum::values();
        $regStatuses = [
            RegistrationStatus::Open->value,
            RegistrationStatus::Invite->value,
            RegistrationStatus::Closed->value,
        ];
        $captchaRules = ['cf-turnstile-response' => 'required|string'];

        $runBatch = static function (int $iterations): void {
            for ($i = 0; $i < $iterations; $i++) {
                AdminContentRequest::normalizeContentInput([
                    'action' => '  submit  ',
                    'title' => '  Getting Started with NNTmux  ',
                    'url' => '  /guides/getting-started  ',
                    'metadescription' => '  Step-by-step guide to configuring NNTmux  ',
                    'metakeywords' => '  usenet, nzb, guide, setup  ',
                ]);
                AdminContentRequest::isSubmitAction('POST', 'submit');
                RegistrationPeriodRequest::resolveRoutePeriodId('42');
                RegistrationPeriodRequest::intervalsOverlap(1700000000, 1700086400, 1700040000, 1700120000);
                StoreIncidentRequest::incidentValidationSpec(
                    ['none', 'minor', 'major', 'critical'],
                    ['investigating', 'identified', 'monitoring', 'resolved']
                );
                UpdateRegistrationStatusRequest::buildAllowedStatusInRule([0, 1, 2]);
                ContactContactURequest::buildContactRules(true, ['cf-turnstile-response' => 'required|string']);
                ContactContactURequest::contactValidationMessages();
            }
        };

        $setAllModes = static function (bool $rust): void {
            AdminContentRequest::setUseRustFfi($rust);
            RegistrationPeriodRequest::setUseRustFfi($rust);
            StoreIncidentRequest::setUseRustFfi($rust);
            UpdateRegistrationStatusRequest::setUseRustFfi($rust);
            ContactContactURequest::setUseRustFfi($rust);
        };

        // Warmup >= 5 iterations
        for ($w = 0; $w < 8; $w++) {
            $setAllModes(false);
            $runBatch(10);
            $setAllModes(true);
            $runBatch(10);
        }

        $rounds = 3;
        $samples = 41;
        $iterations = 200;
        $summary = [];

        for ($round = 1; $round <= $rounds; $round++) {
            $phpTimes = [];
            $rustTimes = [];

            for ($s = 0; $s < $samples; $s++) {
                $rustFirst = (($round + $s) % 2) === 1;
                if ($rustFirst) {
                    $setAllModes(true);
                    $t0 = hrtime(true);
                    $runBatch($iterations);
                    $rustTimes[] = (hrtime(true) - $t0) / 1_000_000;

                    $setAllModes(false);
                    $t0 = hrtime(true);
                    $runBatch($iterations);
                    $phpTimes[] = (hrtime(true) - $t0) / 1_000_000;
                } else {
                    $setAllModes(false);
                    $t0 = hrtime(true);
                    $runBatch($iterations);
                    $phpTimes[] = (hrtime(true) - $t0) / 1_000_000;

                    $setAllModes(true);
                    $t0 = hrtime(true);
                    $runBatch($iterations);
                    $rustTimes[] = (hrtime(true) - $t0) / 1_000_000;
                }
            }

            sort($phpTimes);
            sort($rustTimes);
            $phpMedian = $phpTimes[intdiv($samples, 2)];
            $rustMedian = $rustTimes[intdiv($samples, 2)];
            $speedup = $phpMedian / max(0.0001, $rustMedian);
            $summary[] = sprintf(
                'Round %d: PHP median=%.3f ms, Rust median=%.3f ms, speedup=%.2fx',
                $round,
                $phpMedian,
                $rustMedian,
                $speedup
            );
        }

        fwrite(STDERR, "\n[Wave 23.5 Benchmark] ".implode(' | ', $summary)."\n");
        $this->assertCount(3, $summary);
        $this->assertNotEmpty($contentInputs);
        $this->assertNotEmpty($impacts);
        $this->assertNotEmpty($statuses);
        $this->assertNotEmpty($regStatuses);
        $this->assertNotEmpty($captchaRules);
    }

    private static function callFfi(FFI $ffi, string $symbol, mixed ...$args): mixed
    {
        return $ffi->$symbol(...$args);
    }

    private function invokePrepareForValidation(object $request): void
    {
        $ref = new ReflectionMethod($request, 'prepareForValidation');
        $ref->invoke($request);
    }

    private function requireCategorizeFfi(): FFI
    {
        $ffi = RustPipelineLibrary::resolveDomain('categorize', self::DIRECT_CDEF);
        if ($ffi === null) {
            $this->markTestSkipped('Rust categorize FFI library is not built or available.');
        }

        return $ffi;
    }
}
