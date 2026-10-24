<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\RegistrationStatus;
use App\Enums\ServiceStatusEnum;
use App\Enums\TmuxBackfillMode;
use App\Enums\TmuxMode;
use App\Enums\TmuxPaneRole;
use App\Enums\TmuxPostProcessMode;
use App\Enums\TmuxSequentialMode;
use App\Models\RegistrationConfiguration;
use App\Models\SiteConfiguration;
use App\Models\TmuxCleanupRule;
use App\Models\TmuxColorExclusion;
use App\Models\TmuxConfiguration;
use App\Support\Configuration\RegistrationConfigurationData;
use App\Support\Configuration\SiteConfigurationData;
use App\Support\Configuration\TmuxConfigurationData;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * Wave 19 Slice 19.6 parity and paired benchmark suite for `TmuxConfigurationData`,
 * `SiteConfigurationData`, `RegistrationConfigurationData`, `ServiceStatusEnum`,
 * and `TmuxMode` (`rust/pipeline-ffi/src/categorize.rs`).
 *
 * Reproduce paired randomized benchmarks:
 *   NNTMUX_TMUX_SITE_CONFIG_STATUS_ENUMS_BENCH=1 php artisan test --compact tests/Unit/RustTmuxSiteConfigStatusEnumsParityTest.php --filter=test_benchmark_paired_workloads
 *
 * Protocol: seed 20261024; 512 warmups; 3 repetitions of 4,096 randomized paired
 * single calls per workload (common and uncommon); nearest-rank p99; output equality
 * verified before timing. Adoption requires >= 1.5x speedup on all 4 metrics
 * (common mean, common p99, uncommon mean, uncommon p99) per `.ai/rules/rust-performance.md`.
 *
 * Paired speedup results (`PHP latency / Rust FFI latency`, mean/p99 across 3 reps):
 * - `tmuxDefaults`:          common 0.32x/0.31x, 0.33x/0.33x, 0.32x/0.33x | uncommon 0.21x/0.22x, 0.21x/0.21x, 0.21x/0.22x
 * - `tmuxFromModel`:         common 0.95x/1.00x, 0.95x/0.94x, 0.95x/1.02x | uncommon 0.96x/0.96x, 0.96x/0.90x, 0.96x/0.92x
 * - `tmuxToArray`:           common 0.20x/0.20x, 0.21x/0.22x, 0.21x/0.22x | uncommon 0.98x/0.98x, 0.99x/0.99x, 0.98x/0.94x
 * - `siteDefaults`:          common 0.37x/0.37x, 0.37x/0.37x, 0.37x/0.36x | uncommon 0.26x/0.27x, 0.25x/0.27x, 0.25x/0.27x
 * - `siteFromModel`:         common 0.91x/0.91x, 0.92x/0.94x, 0.91x/0.86x | uncommon 0.91x/0.93x, 0.92x/0.99x, 0.91x/0.90x
 * - `siteToArray`:           common 0.31x/0.33x, 0.30x/0.36x, 0.29x/0.33x | uncommon 0.96x/0.96x, 0.95x/0.95x, 0.95x/0.93x
 * - `regDefaults`:           common 0.45x/0.50x, 0.46x/0.50x, 0.46x/0.50x | uncommon 0.28x/0.33x, 0.28x/0.33x, 0.27x/0.28x
 * - `regToArray`:            common 0.31x/0.50x, 0.29x/0.50x, 0.30x/0.50x | uncommon 0.30x/0.40x, 0.30x/0.40x, 0.29x/0.40x
 * - `serviceStatusLabel`:    common 0.17x/0.17x, 0.17x/0.28x, 0.17x/0.17x | uncommon 0.20x/0.34x, 0.20x/0.29x, 0.19x/0.33x
 * - `serviceStatusSeverity`: common 0.35x/0.34x, 0.35x/0.34x, 0.34x/0.34x | uncommon 0.47x/0.67x, 0.46x/0.67x, 0.45x/0.67x
 * - `serviceStatusValues`:   common 0.36x/0.40x, 0.36x/0.37x, 0.34x/0.33x | uncommon 0.38x/0.40x, 0.37x/0.40x, 0.37x/0.42x
 * - `tmuxModeTasks`:         common 0.16x/0.17x, 0.16x/0.15x, 0.16x/0.18x | uncommon 0.16x/0.19x, 0.16x/0.19x, 0.16x/0.20x
 *
 * Single-call FFI boundary marshaling overhead dominates these sub-microsecond
 * scalar/enum lookups and array constructions, so no method exceeds 1.5x across
 * all four required metrics. Per `.ai/rules/rust-performance.md`, `$useRustFfi = false`
 * is retained as the production default across all five classes/enums while keeping
 * `*Native` and `*Php` methods tested for 100% parity.
 */
final class RustTmuxSiteConfigStatusEnumsParityTest extends TestCase
{
    private const string FULL_CDEF = TmuxConfigurationData::FFI_CDEF."\n"
        .SiteConfigurationData::FFI_CDEF."\n"
        .RegistrationConfigurationData::FFI_CDEF."\n"
        .ServiceStatusEnum::FFI_CDEF."\n"
        .TmuxMode::FFI_CDEF;

    protected function tearDown(): void
    {
        TmuxConfigurationData::setUseRustFfi(null);
        SiteConfigurationData::setUseRustFfi(null);
        RegistrationConfigurationData::setUseRustFfi(null);
        ServiceStatusEnum::setUseRustFfi(null);
        TmuxMode::setUseRustFfi(null);
        parent::tearDown();
    }

    private static function ffi(): FFI
    {
        $ffi = RustPipelineLibrary::resolveDomain('categorize', self::FULL_CDEF);
        self::assertNotNull($ffi, 'Expected libnntmux_pipeline categorize domain FFI to be available.');

        return $ffi;
    }

    /**
     * @param  list<string>  $rules
     * @param  list<int>  $colors
     * @param  array<string, mixed>  $overrides
     */
    private static function makeTmuxModel(array $rules = [], array $colors = [], array $overrides = []): TmuxConfiguration
    {
        $model = new TmuxConfiguration;
        $defaults = [
            'session_name' => 'nntmux',
            'monitor_delay' => 30,
            'niceness' => 19,
            'sequential_mode' => TmuxSequentialMode::Full,
            'sequential_timer' => 30,
            'binaries_enabled' => false,
            'binaries_timer' => 30,
            'binaries_kill_timer' => 1,
            'backfill_mode' => TmuxBackfillMode::Disabled,
            'backfill_groups' => 4,
            'backfill_timer' => 30,
            'progressive_backfill' => false,
            'releases_enabled' => false,
            'release_timer' => 30,
            'post_mode' => TmuxPostProcessMode::Disabled,
            'post_timer' => 30,
            'post_kill_timer' => 300,
            'post_amazon_mode' => TmuxPostProcessMode::Disabled,
            'post_amazon_timer' => 30,
            'post_non_mode' => TmuxPostProcessMode::Disabled,
            'post_non_timer' => 30,
            'fix_names_enabled' => false,
            'fix_timer' => 30,
            'cleanup_mode' => 'Disabled',
            'cleanup_timer' => 30,
            'run_irc_scraper' => false,
            'console_enabled' => false,
            'htop_enabled' => false,
            'mytop_enabled' => false,
            'nmon_enabled' => false,
            'vnstat_enabled' => false,
            'vnstat_args' => null,
            'tcp_track_enabled' => false,
            'tcp_track_args' => '-i eth0 port 443',
            'bwmng_enabled' => false,
            'redis_enabled' => false,
            'redis_args' => null,
            'write_logs' => false,
            'collections_kill_threshold' => 0,
            'post_process_kill_threshold' => 0,
            'colors_start' => 1,
            'colors_end' => 250,
        ];
        foreach (array_replace($defaults, $overrides) as $key => $val) {
            $model->setAttribute($key, $val);
        }

        $ruleModels = new EloquentCollection(array_map(static function (string $rule): TmuxCleanupRule {
            $m = new TmuxCleanupRule;
            $m->setAttribute('rule', $rule);

            return $m;
        }, $rules));
        $colorModels = new EloquentCollection(array_map(static function (int $color): TmuxColorExclusion {
            $m = new TmuxColorExclusion;
            $m->setAttribute('color', $color);

            return $m;
        }, $colors));

        $model->setRelation('cleanupRules', $ruleModels);
        $model->setRelation('colorExclusions', $colorModels);

        return $model;
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private static function makeSiteModel(array $overrides = []): SiteConfiguration
    {
        $model = new SiteConfiguration;
        $defaults = [
            'title' => 'NNTmux',
            'home_link' => '/',
            'site_logo' => null,
            'strapline' => 'A great usenet indexer',
            'meta_title' => 'An indexer',
            'meta_description' => 'A usenet indexing website',
            'meta_keywords' => 'usenet,nzbs,cms,community',
            'footer' => 'Usenet binary indexer.',
            'dereferrer_link' => '',
            'terms' => '',
            'trailers_display' => true,
            'trailers_size_x' => 480,
            'trailers_size_y' => 345,
        ];
        foreach (array_replace($defaults, $overrides) as $key => $val) {
            $model->setAttribute($key, $val);
        }

        return $model;
    }

    public function test_tmux_configuration_data_defaults_from_model_and_to_array_match_php(): void
    {
        self::ffi();
        self::assertFalse(TmuxConfigurationData::usesRustFfi());

        $expectedDefaults = TmuxConfigurationData::defaultsPhp();
        $nativeDefaults = TmuxConfigurationData::defaultsNative();
        self::assertEquals($expectedDefaults, $nativeDefaults);
        self::assertSame($expectedDefaults->toArrayPhp(), $nativeDefaults->toArrayNative());

        foreach ([false, true] as $toggle) {
            TmuxConfigurationData::setUseRustFfi($toggle);
            self::assertEquals($expectedDefaults, TmuxConfigurationData::defaults());
            self::assertSame($expectedDefaults->toArrayPhp(), TmuxConfigurationData::defaults()->toArray());
        }

        $models = [
            self::makeTmuxModel(),
            self::makeTmuxModel(
                ['size', 'gibberish', 'executable', 'sample'],
                [4, 8, 15, 16, 23, 42],
                [
                    'session_name' => 'custom-tmux-session',
                    'monitor_delay' => 60,
                    'niceness' => 5,
                    'sequential_mode' => TmuxSequentialMode::Stripped,
                    'binaries_enabled' => true,
                    'backfill_mode' => TmuxBackfillMode::Safe,
                    'progressive_backfill' => true,
                    'releases_enabled' => true,
                    'post_mode' => TmuxPostProcessMode::All,
                    'post_amazon_mode' => TmuxPostProcessMode::Additional,
                    'post_non_mode' => TmuxPostProcessMode::Nfo,
                    'fix_names_enabled' => true,
                    'cleanup_mode' => 'Custom',
                    'run_irc_scraper' => true,
                    'console_enabled' => true,
                    'htop_enabled' => true,
                    'mytop_enabled' => true,
                    'nmon_enabled' => true,
                    'vnstat_enabled' => true,
                    'vnstat_args' => '-i eth1',
                    'tcp_track_enabled' => true,
                    'tcp_track_args' => null,
                    'bwmng_enabled' => true,
                    'redis_enabled' => true,
                    'redis_args' => '-s /tmp/redis.sock',
                    'write_logs' => true,
                    'collections_kill_threshold' => 1000,
                    'post_process_kill_threshold' => 500,
                    'colors_start' => 10,
                    'colors_end' => 200,
                ]
            ),
            self::makeTmuxModel(
                ['', 'codec', 'passworded'],
                [0, 255, PHP_INT_MAX, PHP_INT_MIN],
                [
                    'session_name' => '',
                    'vnstat_args' => '',
                    'tcp_track_args' => '',
                    'redis_args' => '',
                    'monitor_delay' => PHP_INT_MAX,
                    'niceness' => PHP_INT_MIN,
                ]
            ),
        ];

        foreach ($models as $idx => $model) {
            $expected = TmuxConfigurationData::fromModelPhp($model);
            $actual = TmuxConfigurationData::fromModelNative($model);
            self::assertEquals($expected, $actual, "TmuxConfigurationData::fromModel case {$idx}");
            self::assertSame($expected->toArrayPhp(), $actual->toArrayNative(), "TmuxConfigurationData::toArray case {$idx}");

            foreach ([false, true] as $toggle) {
                TmuxConfigurationData::setUseRustFfi($toggle);
                $dispatched = TmuxConfigurationData::fromModel($model);
                self::assertEquals($expected, $dispatched);
                self::assertSame($expected->toArrayPhp(), $dispatched->toArray());
            }
        }

        $ruleInputs = [
            [],
            ['size', 'gibberish', 'sample'],
            [['rule' => 'executable'], (object) ['rule' => 'installbin'], 123, null],
            ["with\0embedded\0nul", 'plain'],
        ];
        foreach ($ruleInputs as $idx => $rules) {
            $expectedRules = TmuxConfigurationData::normalizeCleanupRulesPhp($rules);
            self::assertSame($expectedRules, TmuxConfigurationData::normalizeCleanupRulesNative($rules), "rules case {$idx}");
            foreach ([false, true] as $toggle) {
                TmuxConfigurationData::setUseRustFfi($toggle);
                self::assertSame($expectedRules, TmuxConfigurationData::normalizeCleanupRules($rules));
            }
        }

        $colorInputs = [
            [],
            [4, 8, 9, 11, 60],
            [['color' => '15'], (object) ['color' => 42], true, false, 'not-a-number'],
            [PHP_INT_MIN, -1, 0, 255, PHP_INT_MAX],
        ];
        foreach ($colorInputs as $idx => $colors) {
            $expectedColors = TmuxConfigurationData::normalizeColorExclusionsPhp($colors);
            self::assertSame($expectedColors, TmuxConfigurationData::normalizeColorExclusionsNative($colors), "colors case {$idx}");
            foreach ([false, true] as $toggle) {
                TmuxConfigurationData::setUseRustFfi($toggle);
                self::assertSame($expectedColors, TmuxConfigurationData::normalizeColorExclusions($colors));
            }
        }
    }

    public function test_site_configuration_data_defaults_logo_from_model_and_to_array_match_php(): void
    {
        self::ffi();
        self::assertFalse(SiteConfigurationData::usesRustFfi());

        $expectedDefaults = SiteConfigurationData::defaultsPhp();
        $nativeDefaults = SiteConfigurationData::defaultsNative();
        self::assertEquals($expectedDefaults, $nativeDefaults);
        self::assertSame($expectedDefaults->toArrayPhp(), $nativeDefaults->toArrayNative());

        foreach ([false, true] as $toggle) {
            SiteConfigurationData::setUseRustFfi($toggle);
            self::assertEquals($expectedDefaults, SiteConfigurationData::defaults());
            self::assertSame($expectedDefaults->toArrayPhp(), SiteConfigurationData::defaults()->toArray());
        }

        $logoCases = [
            null,
            '',
            '0',
            ' ',
            '/assets/images/logo.svg',
            "logo\0with\0nul.png",
            str_repeat('/nested/path', 30).'/brand.webp',
        ];
        foreach ($logoCases as $idx => $logo) {
            $expectedLogo = SiteConfigurationData::normalizeLogoPathPhp($logo);
            self::assertSame($expectedLogo, SiteConfigurationData::normalizeLogoPathNative($logo), "logo case {$idx}");
            foreach ([false, true] as $toggle) {
                SiteConfigurationData::setUseRustFfi($toggle);
                self::assertSame($expectedLogo, SiteConfigurationData::normalizeLogoPath($logo));
            }
        }

        $siteModels = [
            self::makeSiteModel(),
            self::makeSiteModel(['site_logo' => '']),
            self::makeSiteModel([
                'title' => 'Custom Indexer',
                'home_link' => '/browse',
                'site_logo' => '/storage/logos/site.webp',
                'strapline' => 'High-speed Usenet Search',
                'meta_title' => 'Search Index',
                'meta_description' => 'Private Usenet Community',
                'meta_keywords' => 'usenet,nzb,api',
                'footer' => 'Copyright 2026',
                'dereferrer_link' => 'https://deref.example.test/?url=',
                'terms' => 'No redistribution.',
                'trailers_display' => false,
                'trailers_size_x' => 1280,
                'trailers_size_y' => 720,
            ]),
            self::makeSiteModel([
                'site_logo' => '0',
                'trailers_display' => false,
                'trailers_size_x' => PHP_INT_MAX,
                'trailers_size_y' => PHP_INT_MIN,
            ]),
        ];

        foreach ($siteModels as $idx => $model) {
            $expected = SiteConfigurationData::fromModelPhp($model);
            $actual = SiteConfigurationData::fromModelNative($model);
            self::assertEquals($expected, $actual, "SiteConfigurationData::fromModel case {$idx}");
            self::assertSame($expected->toArrayPhp(), $actual->toArrayNative(), "SiteConfigurationData::toArray case {$idx}");

            foreach ([false, true] as $toggle) {
                SiteConfigurationData::setUseRustFfi($toggle);
                $dispatched = SiteConfigurationData::fromModel($model);
                self::assertEquals($expected, $dispatched);
                self::assertSame($expected->toArrayPhp(), $dispatched->toArray());
            }
        }
    }

    public function test_registration_configuration_data_matches_php(): void
    {
        self::ffi();
        self::assertFalse(RegistrationConfigurationData::usesRustFfi());

        $expectedDefaults = RegistrationConfigurationData::defaultsPhp();
        $nativeDefaults = RegistrationConfigurationData::defaultsNative();
        self::assertEquals($expectedDefaults, $nativeDefaults);
        self::assertSame($expectedDefaults->toArrayPhp(), $nativeDefaults->toArrayNative());

        foreach ([false, true] as $toggle) {
            RegistrationConfigurationData::setUseRustFfi($toggle);
            self::assertEquals($expectedDefaults, RegistrationConfigurationData::defaults());
        }

        foreach (RegistrationStatus::cases() as $status) {
            $model = new RegistrationConfiguration;
            $model->setAttribute('status', $status);

            $expected = RegistrationConfigurationData::fromModelPhp($model);
            $actual = RegistrationConfigurationData::fromModelNative($model);
            self::assertEquals($expected, $actual);
            self::assertSame($expected->toArrayPhp(), $actual->toArrayNative());
            self::assertSame($expected->statusLabelPhp(), $actual->statusLabelNative());

            foreach ([false, true] as $toggle) {
                RegistrationConfigurationData::setUseRustFfi($toggle);
                $dispatched = RegistrationConfigurationData::fromModel($model);
                self::assertEquals($expected, $dispatched);
                self::assertSame($expected->toArrayPhp(), $dispatched->toArray());
                self::assertSame($expected->statusLabelPhp(), $dispatched->statusLabel());
            }
        }
    }

    public function test_service_status_enum_and_tmux_mode_match_php(): void
    {
        self::ffi();
        self::assertFalse(ServiceStatusEnum::usesRustFfi());
        self::assertFalse(TmuxMode::usesRustFfi());

        self::assertSame(ServiceStatusEnum::valuesPhp(), ServiceStatusEnum::valuesNative());
        foreach ([false, true] as $toggle) {
            ServiceStatusEnum::setUseRustFfi($toggle);
            self::assertSame(ServiceStatusEnum::valuesPhp(), ServiceStatusEnum::values());
        }

        foreach (ServiceStatusEnum::cases() as $status) {
            $expectedLabel = $status->labelPhp();
            $expectedSeverity = $status->severityPhp();
            self::assertSame($expectedLabel, $status->labelNative());
            self::assertSame($expectedSeverity, $status->severityNative());

            foreach ([false, true] as $toggle) {
                ServiceStatusEnum::setUseRustFfi($toggle);
                self::assertSame($expectedLabel, $status->label());
                self::assertSame($expectedSeverity, $status->severity());
            }
        }

        foreach (TmuxMode::cases() as $mode) {
            $expectedTasks = $mode->tasksPhp();
            $nativeTasks = $mode->tasksNative();
            self::assertSame($expectedTasks, $nativeTasks);
            self::assertSame(array_keys($expectedTasks), array_keys($nativeTasks));

            foreach ([false, true] as $toggle) {
                TmuxMode::setUseRustFfi($toggle);
                self::assertSame($expectedTasks, $mode->tasks());
            }
        }
    }

    public function test_direct_c_abi_null_overflow_alignment_and_buffer_contracts(): void
    {
        $ffi = self::ffi();
        $rawBytes = $ffi->new('uint8_t[512]');
        $unalignedI64 = $ffi->cast('int64_t *', FFI::addr($rawBytes[1]));
        $unalignedUsize = $ffi->cast('size_t *', FFI::addr($rawBytes[1]));

        $tmuxScalars = $ffi->new('int64_t[40]');
        $tmuxExclusions = $ffi->new('int64_t[19]');
        $siteScalars = $ffi->new('int64_t[4]');
        $regOut = $ffi->new('int64_t[1]');
        $countSlot = $ffi->new('size_t[1]');
        $smallBuf = $ffi->new('char[4]');
        $buf = $ffi->new('char[256]');

        // nntmux_tmux_config_defaults contracts
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_tmux_config_defaults(null, 40, $tmuxExclusions, 19, $buf, 256));
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_tmux_config_defaults($unalignedI64, 40, $tmuxExclusions, 19, $buf, 256));
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_tmux_config_defaults($tmuxScalars, 39, $tmuxExclusions, 19, $buf, 256));
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_tmux_config_defaults($tmuxScalars, 40, null, 19, $buf, 256));
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_tmux_config_defaults($tmuxScalars, 40, $unalignedI64, 19, $buf, 256));
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_tmux_config_defaults($tmuxScalars, 40, $tmuxExclusions, 18, $buf, 256));
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_tmux_config_defaults($tmuxScalars, 40, $tmuxExclusions, 19, null, 16));
        // @phpstan-ignore method.notFound
        self::assertSame(-34, $ffi->nntmux_tmux_config_defaults($tmuxScalars, 40, $tmuxExclusions, 19, $smallBuf, 4));
        // @phpstan-ignore method.notFound
        self::assertSame(34, $ffi->nntmux_tmux_config_defaults($tmuxScalars, 40, $tmuxExclusions, 19, $buf, 256));

        // nntmux_tmux_config_normalize_scalars contracts
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_tmux_config_normalize_scalars(null, 37, $tmuxScalars, 37));
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_tmux_config_normalize_scalars($unalignedI64, 37, $tmuxScalars, 37));
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_tmux_config_normalize_scalars($tmuxScalars, 37, $unalignedI64, 37));
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_tmux_config_normalize_scalars($tmuxScalars, 36, $tmuxScalars, 37));

        // nntmux_tmux_config_normalize_color_exclusions contracts
        // @phpstan-ignore method.notFound
        self::assertSame(0, $ffi->nntmux_tmux_config_normalize_color_exclusions(null, 0, $tmuxExclusions, 19));
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_tmux_config_normalize_color_exclusions(null, 4, $tmuxExclusions, 19));
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_tmux_config_normalize_color_exclusions($unalignedI64, 4, $tmuxExclusions, 19));
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_tmux_config_normalize_color_exclusions($tmuxExclusions, 4, $unalignedI64, 19));
        // @phpstan-ignore method.notFound
        self::assertSame(-19, $ffi->nntmux_tmux_config_normalize_color_exclusions($tmuxExclusions, 19, $siteScalars, 4));

        // nntmux_tmux_config_normalize_cleanup_rules_nul contracts
        // @phpstan-ignore method.notFound
        self::assertSame(0, $ffi->nntmux_tmux_config_normalize_cleanup_rules_nul(null, 0, 0, $buf, 256));
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_tmux_config_normalize_cleanup_rules_nul('size', 4, 0, $buf, 256));
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_tmux_config_normalize_cleanup_rules_nul('size', 4, 2, $buf, 256));
        // @phpstan-ignore method.notFound
        self::assertSame(-14, $ffi->nntmux_tmux_config_normalize_cleanup_rules_nul("size\0gibberish", 14, 2, $smallBuf, 4));

        // nntmux_site_config_defaults, normalize_logo, normalize_scalars contracts
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_site_config_defaults(null, 4, $buf, 256));
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_site_config_defaults($unalignedI64, 4, $buf, 256));
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_site_config_defaults($siteScalars, 3, $buf, 256));
        // @phpstan-ignore method.notFound
        self::assertSame(-120, $ffi->nntmux_site_config_defaults($siteScalars, 4, $smallBuf, 4));
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_site_config_normalize_logo(2, 'x', 1, $buf, 256));
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_site_config_normalize_logo(0, 'x', 1, $buf, 256));
        // @phpstan-ignore method.notFound
        self::assertSame(-10, $ffi->nntmux_site_config_normalize_logo(1, '/logo.webp', 10, $smallBuf, 4));
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_site_config_normalize_scalars(2, 1, 480, 345, $siteScalars, 4));
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_site_config_normalize_scalars(1, 1, 480, 345, $unalignedI64, 4));

        // RegistrationConfigurationData contracts
        // @phpstan-ignore method.notFound
        self::assertSame(0, $ffi->nntmux_registration_config_default_status());
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_registration_config_validate_status(0, null));
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_registration_config_validate_status(0, $unalignedI64));
        // @phpstan-ignore method.notFound
        self::assertSame(0, $ffi->nntmux_registration_config_validate_status(-1, $regOut));
        // @phpstan-ignore method.notFound
        self::assertSame(-2, $ffi->nntmux_registration_config_status_label(99, $buf, 256));
        // @phpstan-ignore method.notFound
        self::assertSame(-6, $ffi->nntmux_registration_config_status_label(1, $smallBuf, 4));

        // ServiceStatusEnum and TmuxMode contracts
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_service_status_label(null, 5, $buf, 256));
        // @phpstan-ignore method.notFound
        self::assertSame(-2, $ffi->nntmux_service_status_label('unknown', 7, $buf, 256));
        // @phpstan-ignore method.notFound
        self::assertSame(-14, $ffi->nntmux_service_status_label('partial_outage', 14, $smallBuf, 4));
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_service_status_severity(null, 5));
        // @phpstan-ignore method.notFound
        self::assertSame(-2, $ffi->nntmux_service_status_severity('unknown', 7));
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_service_status_values_nul(null, $buf, 256));
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_service_status_values_nul($unalignedUsize, $buf, 256));
        // @phpstan-ignore method.notFound
        self::assertSame(-60, $ffi->nntmux_service_status_values_nul($countSlot, $smallBuf, 4));
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_tmux_mode_tasks_nul(0, null, $buf, 256));
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_tmux_mode_tasks_nul(0, $unalignedUsize, $buf, 256));
        // @phpstan-ignore method.notFound
        self::assertSame(-2, $ffi->nntmux_tmux_mode_tasks_nul(99, $countSlot, $buf, 256));
        // @phpstan-ignore method.notFound
        self::assertSame(-75, $ffi->nntmux_tmux_mode_tasks_nul(2, $countSlot, $smallBuf, 4));
    }

    public function test_native_methods_fall_back_to_php_when_ffi_unavailable(): void
    {
        TmuxConfigurationData::setFfiForTesting(false);
        TmuxConfigurationData::setUseRustFfi(true);
        SiteConfigurationData::setFfiForTesting(false);
        SiteConfigurationData::setUseRustFfi(true);
        RegistrationConfigurationData::setFfiForTesting(false);
        RegistrationConfigurationData::setUseRustFfi(true);
        ServiceStatusEnum::setFfiForTesting(false);
        ServiceStatusEnum::setUseRustFfi(true);
        TmuxMode::setFfiForTesting(false);
        TmuxMode::setUseRustFfi(true);

        self::assertEquals(TmuxConfigurationData::defaultsPhp(), TmuxConfigurationData::defaultsNative());
        self::assertSame(
            TmuxConfigurationData::defaultsPhp()->toArrayPhp(),
            TmuxConfigurationData::defaultsPhp()->toArrayNative()
        );
        $tmuxModel = self::makeTmuxModel(['size', 'sample'], [4, 8]);
        self::assertEquals(TmuxConfigurationData::fromModelPhp($tmuxModel), TmuxConfigurationData::fromModelNative($tmuxModel));

        self::assertEquals(SiteConfigurationData::defaultsPhp(), SiteConfigurationData::defaultsNative());
        self::assertSame(
            SiteConfigurationData::defaultsPhp()->toArrayPhp(),
            SiteConfigurationData::defaultsPhp()->toArrayNative()
        );
        self::assertNull(SiteConfigurationData::normalizeLogoPathNative(''));
        self::assertSame('/logo.png', SiteConfigurationData::normalizeLogoPathNative('/logo.png'));

        self::assertEquals(RegistrationConfigurationData::defaultsPhp(), RegistrationConfigurationData::defaultsNative());
        self::assertSame(['status' => 0], RegistrationConfigurationData::defaultsPhp()->toArrayNative());
        self::assertSame('Open', RegistrationConfigurationData::defaultsPhp()->statusLabelNative());

        self::assertSame('Partial outage', ServiceStatusEnum::PartialOutage->labelNative());
        self::assertSame(3, ServiceStatusEnum::PartialOutage->severityNative());
        self::assertSame(ServiceStatusEnum::valuesPhp(), ServiceStatusEnum::valuesNative());

        self::assertSame(TmuxMode::Full->tasksPhp(), TmuxMode::Full->tasksNative());
        self::assertSame([
            'main' => TmuxPaneRole::Sequential,
            'fixnames' => TmuxPaneRole::FixNames,
            'amazon' => TmuxPaneRole::PostMetadata,
            'scraper' => TmuxPaneRole::IrcScraper,
        ], TmuxMode::Stripped->tasksNative());
    }

    public function test_benchmark_paired_workloads(): void
    {
        if (getenv('NNTMUX_TMUX_SITE_CONFIG_STATUS_ENUMS_BENCH') !== '1') {
            $this->markTestSkipped('Set NNTMUX_TMUX_SITE_CONFIG_STATUS_ENUMS_BENCH=1 to run paired benchmarks.');
        }

        self::ffi();
        $tmuxDefault = TmuxConfigurationData::defaultsPhp();
        $tmuxModelCommon = self::makeTmuxModel(['size', 'gibberish'], [4, 8, 9, 11]);
        $tmuxModelUncommon = self::makeTmuxModel(
            ['size', 'gibberish', 'executable', 'installbin', 'passworded', 'passwordurl', 'sample', 'scr', 'short', 'codec', 'huge'],
            range(1, 64),
            ['vnstat_args' => '-i eth0', 'redis_args' => '-s /var/run/redis/redis.sock', 'binaries_enabled' => true]
        );
        $siteDefault = SiteConfigurationData::defaultsPhp();
        $siteModelCommon = self::makeSiteModel();
        $siteModelUncommon = self::makeSiteModel([
            'site_logo' => '/storage/branding/custom-logo.webp',
            'terms' => str_repeat('Terms and conditions paragraph. ', 16),
            'trailers_display' => false,
        ]);
        $regOpen = new RegistrationConfigurationData(RegistrationStatus::Open);
        $regClosed = new RegistrationConfigurationData(RegistrationStatus::Closed);

        $workloads = [
            'tmuxDefaults/common' => [
                static fn () => TmuxConfigurationData::defaultsPhp()->toArrayPhp(),
                static fn () => TmuxConfigurationData::defaultsNative()->toArrayPhp(),
            ],
            'tmuxDefaults/uncommon' => [
                static fn () => TmuxConfigurationData::defaultsPhp()->colorExclusions,
                static fn () => TmuxConfigurationData::defaultsNative()->colorExclusions,
            ],
            'tmuxFromModel/common' => [
                static fn () => TmuxConfigurationData::fromModelPhp($tmuxModelCommon)->toArrayPhp(),
                static fn () => TmuxConfigurationData::fromModelNative($tmuxModelCommon)->toArrayPhp(),
            ],
            'tmuxFromModel/uncommon' => [
                static fn () => TmuxConfigurationData::fromModelPhp($tmuxModelUncommon)->toArrayPhp(),
                static fn () => TmuxConfigurationData::fromModelNative($tmuxModelUncommon)->toArrayPhp(),
            ],
            'tmuxToArray/common' => [
                static fn () => $tmuxDefault->toArrayPhp(),
                static fn () => $tmuxDefault->toArrayNative(),
            ],
            'tmuxToArray/uncommon' => [
                static fn () => TmuxConfigurationData::fromModelPhp($tmuxModelUncommon)->toArrayPhp(),
                static fn () => TmuxConfigurationData::fromModelPhp($tmuxModelUncommon)->toArrayNative(),
            ],
            'siteDefaults/common' => [
                static fn () => SiteConfigurationData::defaultsPhp()->toArrayPhp(),
                static fn () => SiteConfigurationData::defaultsNative()->toArrayPhp(),
            ],
            'siteDefaults/uncommon' => [
                static fn () => SiteConfigurationData::defaultsPhp()->metaKeywords,
                static fn () => SiteConfigurationData::defaultsNative()->metaKeywords,
            ],
            'siteFromModel/common' => [
                static fn () => SiteConfigurationData::fromModelPhp($siteModelCommon)->toArrayPhp(),
                static fn () => SiteConfigurationData::fromModelNative($siteModelCommon)->toArrayPhp(),
            ],
            'siteFromModel/uncommon' => [
                static fn () => SiteConfigurationData::fromModelPhp($siteModelUncommon)->toArrayPhp(),
                static fn () => SiteConfigurationData::fromModelNative($siteModelUncommon)->toArrayPhp(),
            ],
            'siteToArray/common' => [
                static fn () => $siteDefault->toArrayPhp(),
                static fn () => $siteDefault->toArrayNative(),
            ],
            'siteToArray/uncommon' => [
                static fn () => SiteConfigurationData::fromModelPhp($siteModelUncommon)->toArrayPhp(),
                static fn () => SiteConfigurationData::fromModelPhp($siteModelUncommon)->toArrayNative(),
            ],
            'regDefaults/common' => [
                static fn () => RegistrationConfigurationData::defaultsPhp()->status,
                static fn () => RegistrationConfigurationData::defaultsNative()->status,
            ],
            'regDefaults/uncommon' => [
                static fn () => RegistrationConfigurationData::defaultsPhp()->statusLabelPhp(),
                static fn () => RegistrationConfigurationData::defaultsNative()->statusLabelNative(),
            ],
            'regToArray/common' => [
                static fn () => $regOpen->toArrayPhp(),
                static fn () => $regOpen->toArrayNative(),
            ],
            'regToArray/uncommon' => [
                static fn () => $regClosed->toArrayPhp(),
                static fn () => $regClosed->toArrayNative(),
            ],
            'serviceStatusLabel/common' => [
                static fn () => ServiceStatusEnum::Operational->labelPhp(),
                static fn () => ServiceStatusEnum::Operational->labelNative(),
            ],
            'serviceStatusLabel/uncommon' => [
                static fn () => ServiceStatusEnum::PartialOutage->labelPhp(),
                static fn () => ServiceStatusEnum::PartialOutage->labelNative(),
            ],
            'serviceStatusSeverity/common' => [
                static fn () => ServiceStatusEnum::Operational->severityPhp(),
                static fn () => ServiceStatusEnum::Operational->severityNative(),
            ],
            'serviceStatusSeverity/uncommon' => [
                static fn () => ServiceStatusEnum::MajorOutage->severityPhp(),
                static fn () => ServiceStatusEnum::MajorOutage->severityNative(),
            ],
            'serviceStatusValues/common' => [
                static fn () => ServiceStatusEnum::valuesPhp(),
                static fn () => ServiceStatusEnum::valuesNative(),
            ],
            'serviceStatusValues/uncommon' => [
                static fn () => count(ServiceStatusEnum::valuesPhp()),
                static fn () => count(ServiceStatusEnum::valuesNative()),
            ],
            'tmuxModeTasks/common' => [
                static fn () => TmuxMode::Full->tasksPhp(),
                static fn () => TmuxMode::Full->tasksNative(),
            ],
            'tmuxModeTasks/uncommon' => [
                static fn () => TmuxMode::Stripped->tasksPhp(),
                static fn () => TmuxMode::Stripped->tasksNative(),
            ],
        ];

        $percentile99 = static function (array $ns): float {
            sort($ns);

            return (float) $ns[(int) ceil(count($ns) * 0.99) - 1];
        };

        $random = new Randomizer(new Mt19937(20261024));
        foreach ($workloads as $name => [$phpCall, $rustCall]) {
            self::assertSame($phpCall(), $rustCall(), $name);
            for ($w = 0; $w < 512; $w++) {
                $phpCall();
                $rustCall();
            }

            $reps = [];
            for ($rep = 0; $rep < 3; $rep++) {
                $samples = [[], []];
                for ($pair = 0; $pair < 4096; $pair++) {
                    $first = $random->getInt(0, 1);
                    foreach ([$first, 1 - $first] as $side) {
                        $fn = $side === 0 ? $phpCall : $rustCall;
                        $t0 = hrtime(true);
                        $fn();
                        $samples[$side][] = hrtime(true) - $t0;
                    }
                }
                $phpMean = array_sum($samples[0]) / count($samples[0]);
                $rustMean = array_sum($samples[1]) / count($samples[1]);
                $reps[] = sprintf(
                    'rep%d=%.2fx/%.2fx (php %.0f/%.0fns rust %.0f/%.0fns)',
                    $rep + 1,
                    $phpMean / $rustMean,
                    $percentile99($samples[0]) / $percentile99($samples[1]),
                    $phpMean,
                    $percentile99($samples[0]),
                    $rustMean,
                    $percentile99($samples[1]),
                );
            }
            fwrite(STDOUT, sprintf("%-30s %s\n", $name, implode('  ', $reps)));
        }
    }
}
