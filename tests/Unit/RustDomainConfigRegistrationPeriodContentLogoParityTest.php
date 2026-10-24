<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Http\Requests\Admin\UpdateDomainConfigurationRequest;
use App\Models\Content;
use App\Models\RegistrationPeriod;
use App\Services\RegistrationStatusService;
use App\Services\SiteLogoService;
use App\Support\RustPipelineLibrary;
use Carbon\Carbon;
use Closure;
use FFI;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Parity, C-ABI contract, fallback, and paired benchmark verification for Wave 22 Slice 22.3:
 * UpdateDomainConfigurationRequest, RegistrationStatusService, RegistrationPeriod, Content and
 * SiteLogoService.
 *
 * Benchmarks run only with NNTMUX_DOMAIN_CONFIG_REGISTRATION_BENCH=1:
 *   cargo build --manifest-path rust/pipeline-ffi/Cargo.toml --release
 *   NNTMUX_DOMAIN_CONFIG_REGISTRATION_BENCH=1 php artisan test --compact tests/Unit/RustDomainConfigRegistrationPeriodContentLogoParityTest.php
 *
 * Paired randomized benchmark evidence (3 repetitions x 41 paired samples x 200 inner loops,
 * worst-repetition PHP latency / Rust latency):
 *
 * Apple M5, PHP 8.5.12 NTS, rustc 1.99.1 release profile, base a712f93ab.
 *
 * | Method                                                  | Common (Mean / P99) | Uncommon (Mean / P99) | >= 1.5x | Default     |
 * |---------------------------------------------------------|---------------------|-----------------------|---------|-------------|
 * | UpdateDomainConfigurationRequest::parseColorExclusions  | 0.74x / 0.53x       | 1.48x / 1.45x         | No      | PHP (false) |
 * | UpdateDomainConfigurationRequest::colorExclusionErrors  | 1.04x / 0.99x       | 5.05x / 4.66x         | No      | PHP (false) |
 * | UpdateDomainConfigurationRequest::colorRangeInvalid     | 0.31x / 0.31x       | 0.31x / 0.27x         | No      | PHP (false) |
 * | UpdateDomainConfigurationRequest::sizePairInvalid       | 0.30x / 0.30x       | 0.32x / 0.31x         | No      | PHP (false) |
 * | RegistrationStatusService::decide                       | 0.38x / 0.38x       | 0.36x / 0.32x         | No      | PHP (false) |
 * | RegistrationStatusService::normalizeManualStatus        | 0.40x / 0.38x       | 0.41x / 0.38x         | No      | PHP (false) |
 * | RegistrationStatusService::labelFor                     | 0.21x / 0.21x       | 0.16x / 0.15x         | No      | PHP (false) |
 * | RegistrationStatusService::statusMessage                | 0.17x / 0.16x       | 0.30x / 0.27x         | No      | PHP (false) |
 * | RegistrationStatusService::manualStatusDescription      | 0.51x / 0.47x       | 0.29x / 0.30x         | No      | PHP (false) |
 * | RegistrationStatusService::periodDescription            | 0.25x / 0.25x       | 0.30x / 0.28x         | No      | PHP (false) |
 * | RegistrationPeriod::isActiveAt                          | 0.65x / 0.64x       | 0.37x / 0.37x         | No      | PHP (false) |
 * | RegistrationPeriod::hasEndedAt                          | 0.30x / 0.32x       | 0.04x / 0.04x         | No      | PHP (false) |
 * | RegistrationPeriod::windowState                         | 0.39x / 0.32x       | 0.38x / 0.33x         | No      | PHP (false) |
 * | Content::contentTypeLabelFor                            | 0.16x / 0.14x       | 0.16x / 0.12x         | No      | PHP (false) |
 * | Content::roleLabelFor                                   | 0.16x / 0.14x       | 0.16x / 0.16x         | No      | PHP (false) |
 * | Content::classifyUrl                                    | 0.38x / 0.35x       | 0.42x / 0.42x         | No      | PHP (false) |
 * | SiteLogoService::urlFor                                 | 0.32x / 0.29x       | 0.18x / 0.17x         | No      | PHP (false) |
 * | SiteLogoService::isManagedPath                          | 0.45x / 0.41x       | 0.18x / 0.18x         | No      | PHP (false) |
 *
 * No method qualifies. Per-call FFI marshalling exceeds these constant-time PHP matches,
 * comparisons, `str_starts_with` checks and short `sprintf` formats; the Carbon-backed window
 * checks also pay for epoch-microsecond conversion. Only the long uncommon color-exclusion
 * error list clears 1.5x, while its common three-token path does not, so every class keeps
 * PHP as the production default.
 */
class RustDomainConfigRegistrationPeriodContentLogoParityTest extends TestCase
{
    /** @var list<class-string> */
    private const array TOGGLED_CLASSES = [
        UpdateDomainConfigurationRequest::class,
        RegistrationStatusService::class,
        RegistrationPeriod::class,
        Content::class,
        SiteLogoService::class,
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->resetFfiState();
        if (RustPipelineLibrary::resolveDomain('additional', UpdateDomainConfigurationRequest::FFI_CDEF) === null) {
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

    public function test_production_defaults_follow_benchmark_gate(): void
    {
        foreach (self::TOGGLED_CLASSES as $class) {
            $this->assertFalse(
                $class::usesRustFfi(),
                "{$class} must keep PHP as default until its Rust path passes the 1.5x gate across common and uncommon mean/p99"
            );
        }
    }

    public function test_toggle_api_round_trips(): void
    {
        foreach (self::TOGGLED_CLASSES as $class) {
            $class::enableRustFfi();
            $this->assertTrue($class::usesRustFfi(), $class);
            $class::disableRustFfi();
            $this->assertFalse($class::usesRustFfi(), $class);
            $class::enableRustFfi();
            $class::resetRustFfi();
            $this->assertFalse($class::usesRustFfi(), $class);
        }
    }

    // ---------------------------------------------------------------------
    // 1. UpdateDomainConfigurationRequest
    // ---------------------------------------------------------------------

    /**
     * @return list<string>
     */
    private static function colorInputs(): array
    {
        $inputs = [
            '', ' ', ',', " \t,\n", '1', '1 2 3', '3,1,3,2', " 3, 1 3,,2\x0B4\x0C5\r\n1 ", "\0 7 \0", "\x85 1\xA0",
            '12abc abc +5 -0 0x1A 00300 5', '1e3 1.9 .5 5E1 -.5 1e 1.', '99999999999999999999 -99999999999999999999x',
            '9223372036854775807 9223372036854775808 -9223372036854775808', '0 255 256 0255 -1 +7', 'é 12 ١',
            implode(',', range(0, 255)), str_repeat('1 2 ', 400),
        ];
        mt_srand(2203);
        $alphabet = ['0', '1', '2', '5', '9', ' ', ',', "\t", "\n", "\x0B", "\x0C", "\r", "\0", '-', '+', '.', 'e', 'E', 'x', 'a', "\xA0"];
        for ($i = 0; $i < 400; $i++) {
            $value = '';
            $length = mt_rand(0, 24);
            for ($j = 0; $j < $length; $j++) {
                $value .= $alphabet[mt_rand(0, \count($alphabet) - 1)];
            }
            $inputs[] = $value;
        }

        return $inputs;
    }

    public function test_color_exclusions_parity(): void
    {
        foreach (self::colorInputs() as $raw) {
            $expected = array_values(array_unique(array_map('intval', preg_split('/[\s,]+/', trim($raw), -1, PREG_SPLIT_NO_EMPTY) ?: [])));
            $this->assertSame($expected, UpdateDomainConfigurationRequest::parseColorExclusionsPhp($raw), json_encode($raw) ?: '');
            $this->assertSame($expected, UpdateDomainConfigurationRequest::parseColorExclusionsNative($raw), json_encode($raw) ?: '');
        }

        UpdateDomainConfigurationRequest::enableRustFfi();
        $request = UpdateDomainConfigurationRequest::create('/', 'POST', ['color_exclusions' => '4, 2 4']);
        $this->assertSame([4, 2], $request->colorExclusions());
    }

    public function test_color_exclusion_errors_and_range_parity(): void
    {
        $ranges = [[0, 255], [5, 10], [10, 5], [0, 0], [-5, 300], [PHP_INT_MIN, PHP_INT_MAX], [200, 255]];
        foreach (self::colorInputs() as $index => $raw) {
            [$start, $end] = $ranges[$index % \count($ranges)];
            $php = UpdateDomainConfigurationRequest::colorExclusionErrorsPhp($raw, $start, $end);
            $this->assertSame($php, UpdateDomainConfigurationRequest::colorExclusionErrorsNative($raw, $start, $end), json_encode($raw) ?: '');
        }
        $this->assertSame([
            'Excluded color 300 must be an integer from 0 through 255.',
            'Excluded color abc must be an integer from 0 through 255.',
            'Excluded color 0 must be inside the configured color range.',
            'Excluded color 255 must be inside the configured color range.',
        ], UpdateDomainConfigurationRequest::colorExclusionErrorsNative('300 abc 0 7 0255', 5, 10));
        $this->assertSame([], UpdateDomainConfigurationRequest::colorExclusionErrorsNative('', 5, 10));

        $values = [PHP_INT_MIN, -1, 0, 1, 5, 255, PHP_INT_MAX];
        foreach ($values as $a) {
            foreach ($values as $b) {
                $this->assertSame($a >= $b, UpdateDomainConfigurationRequest::colorRangeInvalidPhp($a, $b));
                $this->assertSame($a >= $b, UpdateDomainConfigurationRequest::colorRangeInvalidNative($a, $b));
                $this->assertSame($b > 0 && $a > $b, UpdateDomainConfigurationRequest::sizePairInvalidPhp($a, $b));
                $this->assertSame($b > 0 && $a > $b, UpdateDomainConfigurationRequest::sizePairInvalidNative($a, $b));
            }
        }
    }

    // ---------------------------------------------------------------------
    // 2. RegistrationStatusService
    // ---------------------------------------------------------------------

    public function test_registration_status_decision_and_text_parity(): void
    {
        $statuses = [0, 1, 2, 3, -1, 99, PHP_INT_MAX, PHP_INT_MIN];
        foreach ($statuses as $status) {
            foreach ([false, true] as $active) {
                $this->assertSame(
                    RegistrationStatusService::decidePhp($status, $active),
                    RegistrationStatusService::decideNative($status, $active),
                    "{$status} ".var_export($active, true)
                );
                $this->assertSame(
                    RegistrationStatusService::statusMessagePhp($status, $active, '2026-10-24 12:00', 'Launch "week"'),
                    RegistrationStatusService::statusMessageNative($status, $active, '2026-10-24 12:00', 'Launch "week"')
                );
            }
            $this->assertSame(RegistrationStatusService::normalizeManualStatusPhp($status), RegistrationStatusService::normalizeManualStatusNative($status));
            $this->assertSame(RegistrationStatusService::labelForPhp($status), RegistrationStatusService::labelForNative($status));
            foreach ($statuses as $other) {
                $this->assertSame(
                    RegistrationStatusService::manualStatusDescriptionPhp($status, $other),
                    RegistrationStatusService::manualStatusDescriptionNative($status, $other)
                );
            }
        }

        $this->assertSame([
            'effective_status' => 0,
            'scheduled_override_active' => true,
            'reason' => 'scheduled_open_period',
            'available' => true,
            'is_open' => true,
            'is_invite_only' => false,
            'is_closed' => false,
        ], RegistrationStatusService::decideNative(2, true));
        $this->assertSame('Manual registration status changed from Invite to Closed.', RegistrationStatusService::manualStatusDescriptionNative(1, 2));

        $events = [
            RegistrationStatusService::PERIOD_CREATED => 'created',
            RegistrationStatusService::PERIOD_UPDATED => 'updated',
            RegistrationStatusService::PERIOD_ENABLED => 'enabled',
            RegistrationStatusService::PERIOD_DISABLED => 'disabled',
            RegistrationStatusService::PERIOD_COMPLETED => 'completed after reaching its end time',
            RegistrationStatusService::PERIOD_DELETED => 'deleted',
        ];
        foreach ($events as $event => $suffix) {
            foreach (['', 'Spring', 'Ünïcode "quoted" %s', str_repeat('x', 5000)] as $name) {
                $expected = sprintf('Scheduled open period "%s" %s.', $name, $suffix);
                $this->assertSame($expected, RegistrationStatusService::periodDescriptionPhp($event, $name));
                $this->assertSame($expected, RegistrationStatusService::periodDescriptionNative($event, $name));
            }
        }
        $this->expectException(InvalidArgumentException::class);
        RegistrationStatusService::periodDescriptionNative(6, 'x');
    }

    // ---------------------------------------------------------------------
    // 3. RegistrationPeriod
    // ---------------------------------------------------------------------

    public function test_registration_period_window_parity(): void
    {
        $base = Carbon::parse('2026-10-24 12:00:00.250000', 'UTC');
        $offsets = [-86_400_000_000, -1_000_000, -1, 0, 1, 999_999, 1_000_000, 86_400_000_000];
        mt_srand(2213);
        for ($i = 0; $i < 40; $i++) {
            $offsets[] = mt_rand(-5_000_000, 5_000_000);
        }
        $period = new RegistrationPeriod;
        foreach ([true, false] as $enabled) {
            foreach ([null, -1_000_000, 0] as $startOffset) {
                foreach ([null, 0, 1, 2_000_000] as $endOffset) {
                    $period->is_enabled = $enabled;
                    $period->starts_at = $startOffset === null ? null : $base->copy()->addMicroseconds($startOffset);
                    $period->ends_at = $endOffset === null ? null : $base->copy()->addMicroseconds($endOffset)->setTimezone('America/New_York');
                    foreach ($offsets as $offset) {
                        $at = $base->copy()->addMicroseconds($offset)->setTimezone('Asia/Tokyo');
                        $label = json_encode([$enabled, $startOffset, $endOffset, $offset]) ?: '';
                        $this->assertSame($period->isActiveAtPhp($at), $period->isActiveAtNative($at), "active {$label}");
                        $this->assertSame($period->hasEndedAtPhp($at), $period->hasEndedAtNative($at), "ended {$label}");
                        $startsAt = $period->starts_at === null ? null : RegistrationPeriod::epochMicros($period->starts_at);
                        $endsAt = $period->ends_at === null ? null : RegistrationPeriod::epochMicros($period->ends_at);
                        $atMicros = RegistrationPeriod::epochMicros($at);
                        $this->assertNotNull($atMicros);
                        $this->assertSame(
                            RegistrationPeriod::windowStatePhp($enabled, $startsAt, $endsAt, $atMicros),
                            RegistrationPeriod::windowStateNative($enabled, $startsAt, $endsAt, $atMicros)
                        );
                    }
                }
            }
        }

        $this->assertSame(-500_000, RegistrationPeriod::epochMicros(Carbon::parse('1969-12-31 23:59:59.5', 'UTC')));
        $this->assertSame(
            RegistrationPeriod::WINDOW_ACTIVE,
            RegistrationPeriod::windowStateNative(true, PHP_INT_MIN, PHP_INT_MAX, 0)
        );
        RegistrationPeriod::enableRustFfi();
        $period->is_enabled = true;
        $period->starts_at = $base->copy()->subHour();
        $period->ends_at = $base->copy()->addHour();
        $this->assertTrue($period->isActiveAt($base));
        $this->assertFalse($period->hasEndedAt($base));
        $this->assertTrue($period->hasEndedAt($base->copy()->addHours(2)));
    }

    // ---------------------------------------------------------------------
    // 4. Content
    // ---------------------------------------------------------------------

    public function test_content_label_and_url_parity(): void
    {
        foreach ([null, -1, 0, 1, 2, 3, 4, PHP_INT_MAX, PHP_INT_MIN] as $value) {
            $this->assertSame(Content::contentTypeLabelForPhp($value), Content::contentTypeLabelForNative($value), var_export($value, true));
            $this->assertSame(Content::roleLabelForPhp($value), Content::roleLabelForNative($value), var_export($value, true));
        }

        $urls = [
            null, '', '0', '00', '0.0', ' ', 'http://x', 'https://x', 'HTTP://x', 'http:/x', 'google.com', 'chatgpt.ai',
            'a-.co/path', '-a.co', 'a.c', 'a.c1', 'a..com', 'a_b.com', '/google.com', '/contact', 'contact-us',
            'sub.example.org', 'é.com', "a.co\n", 'xn--bcher-kva.example', str_repeat('a', 4000).'.com',
        ];
        mt_srand(2223);
        $alphabet = ['a', 'Z', '0', '9', '-', '.', '/', ':', '_', 'h', 't', 'p', 's', "\xC3\xA9", ' '];
        for ($i = 0; $i < 400; $i++) {
            $url = '';
            $length = mt_rand(0, 16);
            for ($j = 0; $j < $length; $j++) {
                $url .= $alphabet[mt_rand(0, \count($alphabet) - 1)];
            }
            $urls[] = $url;
        }

        $content = new Content;
        foreach ($urls as $url) {
            $label = json_encode($url) ?: '';
            $this->assertSame(Content::classifyUrlPhp($url), Content::classifyUrlNative($url), $label);

            $content->url = $url;
            Content::disableRustFfi();
            $resolved = $content->resolved_url;
            $external = $content->is_external_url;
            Content::enableRustFfi();
            $this->assertSame($resolved, $content->resolved_url, $label);
            $this->assertSame($external, $content->is_external_url, $label);
        }

        $content->contenttype = Content::TYPE_INDEX;
        $content->role = Content::ROLE_LOGGED_IN;
        $content->url = 'google.com';
        $this->assertSame('Homepage', $content->getContentTypeLabel());
        $this->assertSame('Logged in Users', $content->getRoleLabel());
        $this->assertSame('https://google.com', $content->resolved_url);
        $this->assertTrue($content->is_external_url);
        $content->url = '/terms';
        $this->assertSame(url('/terms'), $content->resolved_url);
        $this->assertFalse($content->is_external_url);
    }

    // ---------------------------------------------------------------------
    // 5. SiteLogoService
    // ---------------------------------------------------------------------

    public function test_site_logo_path_parity(): void
    {
        $paths = [
            null, 1, 1.5, true, [], '', 'site-logos', 'site-logos/', 'site-logos/a.png', 'site-logos/../etc/passwd',
            'site-logos/a..b.png', 'Site-logos/a.png', 'x/site-logos/a.png', '/site-logos/a.png', 'site-logos/sub/a.webp',
            "site-logos/a\0.png", 'site-logos/'.str_repeat('é', 3000),
        ];
        $service = new SiteLogoService;
        foreach ($paths as $path) {
            $label = json_encode($path) ?: '';
            $this->assertSame(SiteLogoService::urlForPhp($path), SiteLogoService::urlForNative($path), $label);
            if (\is_string($path)) {
                $this->assertSame(SiteLogoService::isManagedPathPhp($path), SiteLogoService::isManagedPathNative($path), $label);
            }
            SiteLogoService::disableRustFfi();
            $expected = $service->url($path);
            SiteLogoService::enableRustFfi();
            $this->assertSame($expected, $service->url($path), $label);
        }
        $this->assertSame('/storage/site-logos/a.png', SiteLogoService::urlForNative('site-logos/a.png'));
        $this->assertNull(SiteLogoService::urlForNative('site-logos/../a.png'));
    }

    // ---------------------------------------------------------------------
    // C-ABI contracts and fallback
    // ---------------------------------------------------------------------

    private static function ffiCall(FFI $ffi, string $symbol, mixed ...$args): mixed
    {
        return $ffi->$symbol(...$args);
    }

    public function test_c_abi_null_overflow_alignment_and_buffer_contracts(): void
    {
        $config = RustPipelineLibrary::resolveDomain('additional', UpdateDomainConfigurationRequest::FFI_CDEF);
        $this->assertNotNull($config);
        $ints = $config->new('int64_t[4]');
        $bytes = $config->new('uint8_t[64]');
        $unaligned = $config->cast('int64_t*', FFI::addr($bytes[1]));
        $this->assertSame(2, self::ffiCall($config, 'nntmux_domcfg_color_exclusions', '7,7 3', 5, $ints, 4));
        $this->assertSame([7, 3], [$ints[0], $ints[1]]);
        $ints[0] = 42;
        $this->assertSame(-3, self::ffiCall($config, 'nntmux_domcfg_color_exclusions', '1 2 3', 5, $ints, 2));
        $this->assertSame(42, $ints[0]);
        $this->assertSame(-2, self::ffiCall($config, 'nntmux_domcfg_color_exclusions', '1e3', 3, $ints, 4));
        $this->assertSame(-1, self::ffiCall($config, 'nntmux_domcfg_color_exclusions', '1', 1, $unaligned, 4));
        $this->assertSame(-1, self::ffiCall($config, 'nntmux_domcfg_color_exclusions', '1', 1, null, 4));
        $this->assertSame(-1, self::ffiCall($config, 'nntmux_domcfg_color_exclusions', null, 1, $ints, 4));
        $this->assertSame(0, self::ffiCall($config, 'nntmux_domcfg_color_exclusions', null, 0, $ints, 4));

        $text = $config->new('char[256]');
        $small = $config->new('char[8]');
        FFI::memset($small, 0x2A, 8);
        $message = 'Excluded color 300 must be an integer from 0 through 255.';
        $this->assertSame(\strlen($message), self::ffiCall($config, 'nntmux_domcfg_color_exclusion_errors', '300', 3, 0, 10, $text, 256));
        $this->assertSame($message, FFI::string($text, \strlen($message)));
        $this->assertSame(-\strlen($message), self::ffiCall($config, 'nntmux_domcfg_color_exclusion_errors', '300', 3, 0, 10, $small, 8));
        $this->assertSame(str_repeat('*', 8), FFI::string($small, 8));
        $this->assertSame(0, self::ffiCall($config, 'nntmux_domcfg_color_exclusion_errors', '5', 1, 0, 10, $text, 256));
        $this->assertSame(-1, self::ffiCall($config, 'nntmux_domcfg_color_exclusion_errors', null, 3, 0, 10, $text, 256));
        $this->assertSame(-1, self::ffiCall($config, 'nntmux_domcfg_color_exclusion_errors', '300', 3, 0, 10, null, 256));
        $this->assertSame(1, self::ffiCall($config, 'nntmux_domcfg_range_invalid', 0, 5, 5));
        $this->assertSame(0, self::ffiCall($config, 'nntmux_domcfg_range_invalid', 1, 5, 0));
        $this->assertSame(-1, self::ffiCall($config, 'nntmux_domcfg_range_invalid', 2, 0, 0));

        $registration = RustPipelineLibrary::resolveDomain('additional', RegistrationStatusService::FFI_CDEF);
        $this->assertNotNull($registration);
        $decision = $registration->new('int64_t[3]');
        $decisionBytes = $registration->new('uint8_t[32]');
        $this->assertSame(0, self::ffiCall($registration, 'nntmux_regstatus_decide', 1, 0, $decision));
        $this->assertSame([1, 2 | 8, 2], [$decision[0], $decision[1], $decision[2]]);
        $this->assertSame(-1, self::ffiCall($registration, 'nntmux_regstatus_decide', 1, 0, null));
        $this->assertSame(-1, self::ffiCall($registration, 'nntmux_regstatus_decide', 1, 0, $registration->cast('int64_t*', FFI::addr($decisionBytes[1]))));
        $this->assertSame(0, self::ffiCall($registration, 'nntmux_regstatus_normalize_manual', 7));
        $label = $registration->new('char[64]');
        $tiny = $registration->new('char[2]');
        FFI::memset($tiny, 0x2A, 2);
        $this->assertSame(6, self::ffiCall($registration, 'nntmux_regstatus_text', 0, 2, 0, null, 0, null, 0, $label, 64));
        $this->assertSame('Closed', FFI::string($label, 6));
        $this->assertSame(-6, self::ffiCall($registration, 'nntmux_regstatus_text', 0, 2, 0, null, 0, null, 0, $tiny, 2));
        $this->assertSame('**', FFI::string($tiny, 2));
        $this->assertSame(-1, self::ffiCall($registration, 'nntmux_regstatus_text', 3, 6, 0, 'x', 1, null, 0, $label, 64));
        $this->assertSame(-1, self::ffiCall($registration, 'nntmux_regstatus_text', 9, 0, 0, null, 0, null, 0, $label, 64));
        $this->assertSame(-1, self::ffiCall($registration, 'nntmux_regstatus_text', 3, 0, 0, null, 1, null, 0, $label, 64));
        $this->assertSame(-1, self::ffiCall($registration, 'nntmux_regstatus_text', 0, 0, 0, null, 0, null, 0, null, 64));

        $window = RustPipelineLibrary::resolveDomain('additional', RegistrationPeriod::FFI_CDEF);
        $this->assertNotNull($window);
        $this->assertSame(1, self::ffiCall($window, 'nntmux_regperiod_window', 1, 1, 0, 1, 10, 10));
        $this->assertSame(2, self::ffiCall($window, 'nntmux_regperiod_window', 1, 1, 0, 1, 10, 11));
        $this->assertSame(0, self::ffiCall($window, 'nntmux_regperiod_window', 1, 0, 0, 0, 10, 11));

        $content = RustPipelineLibrary::resolveDomain('additional', Content::FFI_CDEF);
        $this->assertNotNull($content);
        $this->assertSame(8, self::ffiCall($content, 'nntmux_content_label', 0, 1, 3, $label, 64));
        $this->assertSame('Homepage', FFI::string($label, 8));
        $this->assertSame(-7, self::ffiCall($content, 'nntmux_content_label', 0, 0, 3, $tiny, 2));
        $this->assertSame('**', FFI::string($tiny, 2));
        $this->assertSame(-1, self::ffiCall($content, 'nntmux_content_label', 2, 1, 0, $label, 64));
        $this->assertSame(-1, self::ffiCall($content, 'nntmux_content_label', 0, 1, 1, null, 64));
        $this->assertSame(2, self::ffiCall($content, 'nntmux_content_url_kind', 'a.io', 4));
        $this->assertSame(0, self::ffiCall($content, 'nntmux_content_url_kind', null, 0));
        $this->assertSame(-1, self::ffiCall($content, 'nntmux_content_url_kind', null, 4));

        $logo = RustPipelineLibrary::resolveDomain('additional', SiteLogoService::FFI_CDEF);
        $this->assertNotNull($logo);
        $this->assertSame(21, self::ffiCall($logo, 'nntmux_site_logo_url', 'site-logos/a', 12, $label, 64));
        $this->assertSame('/storage/site-logos/a', FFI::string($label, 21));
        $this->assertSame(-21, self::ffiCall($logo, 'nntmux_site_logo_url', 'site-logos/a', 12, $tiny, 2));
        $this->assertSame('**', FFI::string($tiny, 2));
        $this->assertSame(0, self::ffiCall($logo, 'nntmux_site_logo_url', 'logos/a', 7, $label, 64));
        $this->assertSame(-1, self::ffiCall($logo, 'nntmux_site_logo_url', null, 7, $label, 64));
        $this->assertSame(-1, self::ffiCall($logo, 'nntmux_site_logo_url', 'site-logos/a', 12, null, 64));
        $this->assertSame(1, self::ffiCall($logo, 'nntmux_site_logo_is_managed', 'site-logos/a', 12));
        $this->assertSame(-1, self::ffiCall($logo, 'nntmux_site_logo_is_managed', null, 1));
    }

    public function test_native_methods_fall_back_to_php_when_ffi_unavailable(): void
    {
        foreach (self::TOGGLED_CLASSES as $class) {
            $class::setFfiForTesting(false);
            $class::enableRustFfi();
        }

        $this->assertSame([3, 1], UpdateDomainConfigurationRequest::parseColorExclusions('3 1 3'));
        $this->assertSame([1000], UpdateDomainConfigurationRequest::parseColorExclusionsNative('1e3'));
        $this->assertSame(['Excluded color x must be an integer from 0 through 255.'], UpdateDomainConfigurationRequest::colorExclusionErrorsNative('x', 0, 9));
        $this->assertTrue(UpdateDomainConfigurationRequest::colorRangeInvalidNative(3, 3));
        $this->assertFalse(UpdateDomainConfigurationRequest::sizePairInvalid(9, 0));
        $this->assertSame('manual_invite', RegistrationStatusService::decideNative(1, false)['reason']);
        $this->assertSame(0, RegistrationStatusService::normalizeManualStatus(8));
        $this->assertSame('Unknown', RegistrationStatusService::labelForNative(8));
        $this->assertSame('Registrations are currently invite only.', RegistrationStatusService::statusMessage(1, false));
        $this->assertSame('Manual registration status note added while status remained Open.', RegistrationStatusService::manualStatusDescriptionNative(0, 0));
        $this->assertSame('Scheduled open period "a" deleted.', RegistrationStatusService::periodDescription(RegistrationStatusService::PERIOD_DELETED, 'a'));
        $this->assertSame(RegistrationPeriod::WINDOW_ENDED, RegistrationPeriod::windowStateNative(true, 0, 1, 2));
        $period = new RegistrationPeriod(['is_enabled' => true, 'starts_at' => Carbon::parse('2026-01-01'), 'ends_at' => Carbon::parse('2026-12-31')]);
        $this->assertTrue($period->isActiveAt(Carbon::parse('2026-06-01')));
        $this->assertFalse($period->hasEndedAtNative(Carbon::parse('2026-06-01')));
        $this->assertSame('Admins', Content::roleLabelForNative(2));
        $this->assertSame('Useful Link', Content::contentTypeLabelFor(1));
        $this->assertSame(Content::URL_BARE_DOMAIN, Content::classifyUrlNative('example.com'));
        $this->assertSame('/storage/site-logos/x', SiteLogoService::urlForNative('site-logos/x'));
        $this->assertFalse(SiteLogoService::isManagedPath('site-logos/../x'));
    }

    // ---------------------------------------------------------------------
    // Paired Randomized Benchmarks
    // ---------------------------------------------------------------------

    public function test_paired_randomized_benchmarks(): void
    {
        if (getenv('NNTMUX_DOMAIN_CONFIG_REGISTRATION_BENCH') !== '1') {
            $this->markTestSkipped('Set NNTMUX_DOMAIN_CONFIG_REGISTRATION_BENCH=1 to run paired Rust/PHP benchmarks.');
        }

        $longColors = implode(', ', array_map(static fn (int $n): string => (string) ($n % 300), range(0, 511)));
        $longName = str_repeat('Holiday open registration ', 40);
        $period = new RegistrationPeriod([
            'is_enabled' => true,
            'starts_at' => Carbon::parse('2026-10-01 00:00:00'),
            'ends_at' => Carbon::parse('2026-10-31 23:59:59'),
        ]);
        $nullEnd = new RegistrationPeriod(['is_enabled' => true, 'starts_at' => Carbon::parse('2026-10-01 00:00:00')]);
        $at = Carbon::parse('2026-10-24 12:00:00.123456');
        $longUrl = 'sub-domain-'.str_repeat('a', 2000).'.example/path';
        $longLogo = 'site-logos/'.str_repeat('nested/', 300).'logo.png';

        $workloads = [
            'UpdateDomainConfigurationRequest::parseColorExclusions' => [
                UpdateDomainConfigurationRequest::class,
                static fn () => UpdateDomainConfigurationRequest::parseColorExclusions('0, 7, 15'),
                static fn () => UpdateDomainConfigurationRequest::parseColorExclusions($longColors),
            ],
            'UpdateDomainConfigurationRequest::colorExclusionErrors' => [
                UpdateDomainConfigurationRequest::class,
                static fn () => UpdateDomainConfigurationRequest::colorExclusionErrors('0, 7, 15', 0, 255),
                static fn () => UpdateDomainConfigurationRequest::colorExclusionErrors($longColors, 20, 200),
            ],
            'UpdateDomainConfigurationRequest::colorRangeInvalid' => [
                UpdateDomainConfigurationRequest::class,
                static fn () => UpdateDomainConfigurationRequest::colorRangeInvalid(0, 255),
                static fn () => UpdateDomainConfigurationRequest::colorRangeInvalid(PHP_INT_MAX, PHP_INT_MIN),
            ],
            'UpdateDomainConfigurationRequest::sizePairInvalid' => [
                UpdateDomainConfigurationRequest::class,
                static fn () => UpdateDomainConfigurationRequest::sizePairInvalid(1_048_576, 0),
                static fn () => UpdateDomainConfigurationRequest::sizePairInvalid(PHP_INT_MAX, 1),
            ],
            'RegistrationStatusService::decide' => [
                RegistrationStatusService::class,
                static fn () => RegistrationStatusService::decide(0, false),
                static fn () => RegistrationStatusService::decide(2, true),
            ],
            'RegistrationStatusService::normalizeManualStatus' => [
                RegistrationStatusService::class,
                static fn () => RegistrationStatusService::normalizeManualStatus(0),
                static fn () => RegistrationStatusService::normalizeManualStatus(PHP_INT_MIN),
            ],
            'RegistrationStatusService::labelFor' => [
                RegistrationStatusService::class,
                static fn () => RegistrationStatusService::labelFor(0),
                static fn () => RegistrationStatusService::labelFor(42),
            ],
            'RegistrationStatusService::statusMessage' => [
                RegistrationStatusService::class,
                static fn () => RegistrationStatusService::statusMessage(0, false),
                static fn () => RegistrationStatusService::statusMessage(0, true, '2026-10-31 23:59', $longName),
            ],
            'RegistrationStatusService::manualStatusDescription' => [
                RegistrationStatusService::class,
                static fn () => RegistrationStatusService::manualStatusDescription(2, 0),
                static fn () => RegistrationStatusService::manualStatusDescription(7, 7),
            ],
            'RegistrationStatusService::periodDescription' => [
                RegistrationStatusService::class,
                static fn () => RegistrationStatusService::periodDescription(RegistrationStatusService::PERIOD_CREATED, 'Spring'),
                static fn () => RegistrationStatusService::periodDescription(RegistrationStatusService::PERIOD_COMPLETED, $longName),
            ],
            'RegistrationPeriod::isActiveAt' => [
                RegistrationPeriod::class,
                static fn () => $period->isActiveAt($at),
                static fn () => $nullEnd->isActiveAt($at),
            ],
            'RegistrationPeriod::hasEndedAt' => [
                RegistrationPeriod::class,
                static fn () => $period->hasEndedAt($at),
                static fn () => $nullEnd->hasEndedAt($at),
            ],
            'RegistrationPeriod::windowState' => [
                RegistrationPeriod::class,
                static fn () => RegistrationPeriod::windowState(true, 1_000, 3_000, 2_000),
                static fn () => RegistrationPeriod::windowState(false, null, PHP_INT_MIN, PHP_INT_MAX),
            ],
            'Content::contentTypeLabelFor' => [
                Content::class,
                static fn () => Content::contentTypeLabelFor(Content::TYPE_USEFUL),
                static fn () => Content::contentTypeLabelFor(null),
            ],
            'Content::roleLabelFor' => [
                Content::class,
                static fn () => Content::roleLabelFor(Content::ROLE_EVERYONE),
                static fn () => Content::roleLabelFor(PHP_INT_MAX),
            ],
            'Content::classifyUrl' => [
                Content::class,
                static fn () => Content::classifyUrl('https://example.com/rules'),
                static fn () => Content::classifyUrl($longUrl),
            ],
            'SiteLogoService::urlFor' => [
                SiteLogoService::class,
                static fn () => SiteLogoService::urlFor('site-logos/abc123.png'),
                static fn () => SiteLogoService::urlFor($longLogo),
            ],
            'SiteLogoService::isManagedPath' => [
                SiteLogoService::class,
                static fn () => SiteLogoService::isManagedPath('site-logos/abc123.png'),
                static fn () => SiteLogoService::isManagedPath($longLogo.'/..'),
            ],
        ];

        $rows = [];
        foreach ($workloads as $name => [$class, $common, $uncommon]) {
            $worst = ['common_mean' => INF, 'common_p99' => INF, 'uncommon_mean' => INF, 'uncommon_p99' => INF];
            for ($rep = 0; $rep < 3; $rep++) {
                foreach (['common' => $common, 'uncommon' => $uncommon] as $kind => $fn) {
                    $class::enableRustFfi();
                    $rustOut = self::invoke($fn);
                    $class::disableRustFfi();
                    $this->assertSame(self::invoke($fn), $rustOut, "{$name} {$kind} benchmark output parity");

                    $stats = $this->runPairedBenchmark($class, $fn, 200, 41, 2203 + $rep);
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
        foreach ([true, false] as $toggle) {
            $toggle ? $class::enableRustFfi() : $class::disableRustFfi();
            for ($w = 0; $w < 50; $w++) {
                $fn();
            }
        }

        $times = [1 => [], 0 => []];
        mt_srand($seed);
        for ($s = 0; $s < $samples; $s++) {
            $order = mt_rand(0, 1) === 0 ? [true, false] : [false, true];
            foreach ($order as $toggle) {
                $toggle ? $class::enableRustFfi() : $class::disableRustFfi();
                $t0 = hrtime(true);
                for ($i = 0; $i < $innerLoops; $i++) {
                    $fn();
                }
                $times[(int) $toggle][] = (hrtime(true) - $t0) / 1000.0 / $innerLoops;
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
