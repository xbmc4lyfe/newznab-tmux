<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\ConfigurationDomain;
use App\Models\IngestionConfiguration;
use App\Models\MetadataConfiguration;
use App\Models\PostProcessingConfiguration;
use App\Models\RegistrationConfiguration;
use App\Models\SiteConfiguration;
use App\Models\TmuxConfiguration;
use App\Services\Configuration\ConfigurationProvider;
use App\Services\Configuration\DomainConfigurationRepository;
use App\Services\Configuration\ProcessingRuntimeStateRepository;
use App\Services\PostProcessService;
use App\Support\RustPipelineLibrary;
use Closure;
use FFI;
use Tests\TestCase;

/**
 * Parity, C-ABI contract, fallback, and paired benchmark verification for Wave 20 Slice 20.3:
 * ConfigurationDomain, ConfigurationProvider, DomainConfigurationRepository,
 * ProcessingRuntimeStateRepository, and PostProcessService.
 *
 * Benchmarks run only with NNTMUX_CONFIG_DOMAIN_RUNTIME_POSTPROCESS_BENCH=1:
 *   cargo build --manifest-path rust/pipeline-ffi/Cargo.toml --release
 *   NNTMUX_CONFIG_DOMAIN_RUNTIME_POSTPROCESS_BENCH=1 php artisan test --compact tests/Unit/RustConfigDomainRuntimePostProcessParityTest.php
 *
 * Paired randomized benchmark evidence (3 repetitions x 41 paired samples x 200 inner loops,
 * worst-repetition PHP latency / Rust latency):
 *
 * Apple M5, PHP 8.5.12 NTS, rustc 1.99.1 release profile, base b007d8199.
 *
 * | Method                                                   | Common (Mean / P99) | Uncommon (Mean / P99) | >= 1.5x | Default     |
 * |----------------------------------------------------------|---------------------|-----------------------|---------|-------------|
 * | ConfigurationDomain::label                               | 0.26x / 0.26x       | 0.30x / 0.30x         | No      | PHP (false) |
 * | ConfigurationDomain::table                               | 0.34x / 0.32x       | 0.26x / 0.25x         | No      | PHP (false) |
 * | ConfigurationProvider::cacheKeyFor                       | 0.26x / 0.25x       | 0.25x / 0.25x         | No      | PHP (false) |
 * | ConfigurationProvider::tableFor                          | 0.41x / 0.39x       | 0.38x / 0.37x         | No      | PHP (false) |
 * | DomainConfigurationRepository::modelClassFor             | 0.27x / 0.25x       | 0.25x / 0.24x         | No      | PHP (false) |
 * | DomainConfigurationRepository::cleanupRuleRows           | 0.29x / 0.30x       | 0.49x / 0.50x         | No      | PHP (false) |
 * | DomainConfigurationRepository::colorExclusionRows        | 0.31x / 0.32x       | 0.72x / 0.71x         | No      | PHP (false) |
 * | ProcessingRuntimeStateRepository::projectMonitorPaths    | 0.32x / 0.30x       | 0.46x / 0.47x         | No      | PHP (false) |
 * | ProcessingRuntimeStateRepository::defaultStateAttributes | 0.36x / 0.34x       | 0.37x / 0.32x         | No      | PHP (false) |
 * | PostProcessService::resolveTvLookupMode                  | 0.24x / 0.23x       | 0.41x / 0.35x         | No      | PHP (false) |
 *
 * No method qualifies: per-call FFI marshalling exceeds these constant-time PHP matches,
 * string concatenation and small array_unique calls, so every class keeps PHP as the
 * production default.
 */
class RustConfigDomainRuntimePostProcessParityTest extends TestCase
{
    /** @var list<class-string> */
    private const array TOGGLED_CLASSES = [
        ConfigurationDomain::class,
        ConfigurationProvider::class,
        DomainConfigurationRepository::class,
        ProcessingRuntimeStateRepository::class,
        PostProcessService::class,
    ];

    private const array MODEL_CLASSES = [
        'site' => SiteConfiguration::class,
        'registration' => RegistrationConfiguration::class,
        'ingestion' => IngestionConfiguration::class,
        'post-processing' => PostProcessingConfiguration::class,
        'metadata' => MetadataConfiguration::class,
        'tmux' => TmuxConfiguration::class,
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->resetFfiState();
        if (RustPipelineLibrary::resolveDomain('additional', ConfigurationDomain::FFI_CDEF) === null) {
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

    public function test_production_defaults_follow_benchmark_gate(): void
    {
        foreach (self::TOGGLED_CLASSES as $class) {
            $this->assertFalse(
                $class::usesRustFfi(),
                "{$class} must keep PHP as default until its Rust path passes the 1.5x gate across common and uncommon mean/p99"
            );
        }
    }

    // ---------------------------------------------------------------------
    // 1. ConfigurationDomain and ConfigurationProvider
    // ---------------------------------------------------------------------

    public function test_configuration_domain_label_table_and_resolution_parity(): void
    {
        $this->assertCount(6, ConfigurationDomain::cases());
        foreach (ConfigurationDomain::cases() as $case) {
            $this->assertSame($case->labelPhp(), $case->labelNative(), $case->name);
            $this->assertSame($case->tablePhp(), $case->tableNative(), $case->name);
            $this->assertSame($case, ConfigurationDomain::tryFromNative($case->value));
        }
        $this->assertSame('Post Processing', ConfigurationDomain::PostProcessing->labelNative());
        $this->assertSame('post_processing_configurations', ConfigurationDomain::PostProcessing->tableNative());

        foreach (['', 'Site', 'SITE', ' site', 'site ', "site\0", 'post_processing', 'postprocessing', 'tmux-', 'métadata', str_repeat('tmux', 300)] as $value) {
            $this->assertSame(ConfigurationDomain::tryFrom($value), ConfigurationDomain::tryFromNative($value), var_export($value, true));
            $this->assertNull(ConfigurationDomain::tryFromNative($value));
        }
        $this->assertNull(ConfigurationDomain::Site->textNative(9));
        $this->assertNull(ConfigurationDomain::Site->textNative(-1));

        ConfigurationDomain::setUseRustFfi(true);
        foreach (ConfigurationDomain::cases() as $case) {
            $this->assertSame($case->labelPhp(), $case->label());
            $this->assertSame($case->tablePhp(), $case->table());
        }
    }

    public function test_configuration_provider_cache_key_and_table_parity(): void
    {
        foreach (ConfigurationDomain::cases() as $case) {
            $this->assertSame('configuration:'.$case->value, ConfigurationProvider::cacheKeyForPhp($case));
            $this->assertSame(ConfigurationProvider::cacheKeyForPhp($case), ConfigurationProvider::cacheKeyForNative($case));
            $this->assertSame($case->tablePhp(), ConfigurationProvider::tableForPhp($case));
            $this->assertSame(ConfigurationProvider::tableForPhp($case), ConfigurationProvider::tableForNative($case));
        }

        ConfigurationProvider::setUseRustFfi(true);
        foreach (ConfigurationDomain::cases() as $case) {
            $this->assertSame('configuration:'.$case->value, ConfigurationProvider::cacheKeyFor($case));
            $this->assertSame($case->tablePhp(), ConfigurationProvider::tableFor($case));
        }
    }

    // ---------------------------------------------------------------------
    // 2. DomainConfigurationRepository
    // ---------------------------------------------------------------------

    public function test_domain_repository_model_class_parity(): void
    {
        foreach (ConfigurationDomain::cases() as $case) {
            $this->assertSame(self::MODEL_CLASSES[$case->value], DomainConfigurationRepository::modelClassForPhp($case));
            $this->assertSame(DomainConfigurationRepository::modelClassForPhp($case), DomainConfigurationRepository::modelClassForNative($case));
        }
        DomainConfigurationRepository::setUseRustFfi(true);
        foreach (ConfigurationDomain::cases() as $case) {
            $this->assertSame(self::MODEL_CLASSES[$case->value], DomainConfigurationRepository::modelClassFor($case));
        }
    }

    public function test_domain_repository_deduplicated_rows_parity(): void
    {
        $ruleSets = [
            [],
            ['tv'],
            ['tv', 'movies', 'tv', 'movies', 'tv'],
            ['', '', 'a', ''],
            ['1', '01', '1.0', ' 1', '1'],
            ["a\0b", "a\0c", "a\0b", 'a'],
            ['Café', 'café', 'Café', 'CAFÉ'],
            [str_repeat('x', 5000), str_repeat('x', 4999), str_repeat('x', 5000)],
        ];
        mt_srand(2003);
        for ($round = 0; $round < 40; $round++) {
            $rules = [];
            $size = mt_rand(1, 120);
            for ($i = 0; $i < $size; $i++) {
                $rules[] = substr(md5((string) mt_rand(0, max(1, intdiv($size, 3)))), 0, mt_rand(0, 32));
            }
            $ruleSets[] = $rules;
        }
        foreach ($ruleSets as $index => $rules) {
            $expected = DomainConfigurationRepository::cleanupRuleRowsPhp($rules);
            $this->assertSame($expected, DomainConfigurationRepository::cleanupRuleRowsNative($rules), "rule set {$index}");
        }

        $colorSets = [
            [],
            [7],
            [1, 2, 1, 3, 2, 1],
            [0, -0, 255, 255, -1, -1],
            [PHP_INT_MAX, PHP_INT_MIN, PHP_INT_MAX, 0, PHP_INT_MIN],
            range(0, 255),
            array_merge(range(255, 0, -1), range(0, 255)),
        ];
        for ($round = 0; $round < 40; $round++) {
            $colors = [];
            $size = mt_rand(1, 300);
            for ($i = 0; $i < $size; $i++) {
                $colors[] = mt_rand(0, 255);
            }
            $colorSets[] = $colors;
        }
        foreach ($colorSets as $index => $colors) {
            $expected = DomainConfigurationRepository::colorExclusionRowsPhp($colors);
            $this->assertSame($expected, DomainConfigurationRepository::colorExclusionRowsNative($colors), "color set {$index}");
        }

        DomainConfigurationRepository::setUseRustFfi(true);
        $this->assertSame(
            [['tmux_configuration_id' => 1, 'rule' => 'b'], ['tmux_configuration_id' => 1, 'rule' => 'a']],
            DomainConfigurationRepository::cleanupRuleRows(['b', 'a', 'b'])
        );
        $this->assertSame(
            [['tmux_configuration_id' => 1, 'color' => 9], ['tmux_configuration_id' => 1, 'color' => 4]],
            DomainConfigurationRepository::colorExclusionRows([9, 4, 9, 4])
        );
    }

    // ---------------------------------------------------------------------
    // 3. ProcessingRuntimeStateRepository
    // ---------------------------------------------------------------------

    public function test_runtime_state_monitor_paths_and_defaults_parity(): void
    {
        $values = [null, '', '/tmp/monitor', "/tmp/with\0nul", str_repeat('/deep', 2000), 'é'];
        foreach ($values as $a) {
            foreach ($values as $b) {
                foreach ($values as $c) {
                    $this->assertSame(
                        ProcessingRuntimeStateRepository::projectMonitorPathsPhp($a, $b, $c),
                        ProcessingRuntimeStateRepository::projectMonitorPathsNative($a, $b, $c)
                    );
                }
            }
        }
        $this->assertSame(
            ['monitor_path' => null, 'monitor_path_a' => '', 'monitor_path_b' => 'x'],
            ProcessingRuntimeStateRepository::projectMonitorPathsNative(null, '', 'x')
        );

        $this->assertSame(
            ProcessingRuntimeStateRepository::defaultStateAttributesPhp(),
            ProcessingRuntimeStateRepository::defaultStateAttributesNative()
        );
        $this->assertSame(
            ['id' => 1, 'tmux_running' => false, 'stop_requested' => false, 'last_binary_run_at' => null, 'monitor_path' => null, 'monitor_path_a' => null, 'monitor_path_b' => null],
            ProcessingRuntimeStateRepository::defaultStateAttributesNative()
        );

        ProcessingRuntimeStateRepository::setUseRustFfi(true);
        $this->assertSame(
            ProcessingRuntimeStateRepository::defaultStateAttributesPhp(),
            ProcessingRuntimeStateRepository::defaultStateAttributes()
        );
        $this->assertSame(
            ['monitor_path' => 'a', 'monitor_path_a' => null, 'monitor_path_b' => 'c'],
            ProcessingRuntimeStateRepository::projectMonitorPaths('a', null, 'c')
        );
    }

    // ---------------------------------------------------------------------
    // 4. PostProcessService
    // ---------------------------------------------------------------------

    public function test_post_process_tv_lookup_mode_parity(): void
    {
        $inputs = [
            null, '', ' ', '0', '1', '2', '3', ' 2', '2 ', "\n2\t", "\v2\f", '+2', '-2', '2.9', '2.9999999999999999999',
            '2e0', '0.2e1', '.2e1', '2.', '200E-2', '+.5', '00002', '-0', '1e100', '-1e100', '99999999999999999999',
            '9223372036854775807', '-9223372036854775809', '1e-400', '.', '1e', '1e+', '-.e1', '0x2', '2abc', '5 .',
            'NAN', 'INF', 'inf', '1_0', "2\0", '٢', 'renamed', 0, 1, 2, 3, -2, PHP_INT_MAX, PHP_INT_MIN,
        ];
        mt_srand(2017);
        for ($i = 0; $i < 200; $i++) {
            $inputs[] = (string) (mt_rand(-5, 5) / mt_rand(1, 4));
            $inputs[] = sprintf('%s%d%s', mt_rand(0, 1) === 1 ? ' ' : '', mt_rand(-3, 3), mt_rand(0, 1) === 1 ? 'e0' : '');
        }
        foreach ($inputs as $input) {
            foreach ([0, 1, 2, 7] as $default) {
                $label = var_export($input, true)." default {$default}";
                $php = PostProcessService::resolveTvLookupModePhp($input, $default);
                $this->assertSame($php, PostProcessService::resolveTvLookupModeNative($input, $default), $label);
                $calls = 0;
                $lazy = static function () use ($default, &$calls): int {
                    $calls++;

                    return $default;
                };
                $this->assertSame($php, PostProcessService::resolveTvLookupModeNative($input, $lazy), $label);
                $this->assertSame(is_numeric($input) ? 0 : 1, $calls, "{$label} lazy default calls");
            }
        }

        PostProcessService::setUseRustFfi(true);
        $this->assertSame(['resolved' => 2, 'renamedOnly' => true], PostProcessService::resolveTvLookupMode('2', 1));
        $this->assertSame(['resolved' => 1, 'renamedOnly' => false], PostProcessService::resolveTvLookupMode('', static fn (): int => 1));
        $this->assertSame(['resolved' => 2, 'renamedOnly' => true], PostProcessService::resolveTvLookupMode(null, 2));
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
        $domainFfi = RustPipelineLibrary::resolveDomain('additional', ConfigurationDomain::FFI_CDEF);
        $this->assertNotNull($domainFfi);
        $text = $domainFfi->new('char[64]');
        $small = $domainFfi->new('char[4]');
        $this->assertSame(3, self::ffiCall($domainFfi, 'nntmux_cfgd_resolve', 'post-processing', 15));
        $this->assertSame(-1, self::ffiCall($domainFfi, 'nntmux_cfgd_resolve', 'post-processing', 14));
        $this->assertSame(-1, self::ffiCall($domainFfi, 'nntmux_cfgd_resolve', null, 4));
        $this->assertSame(-1, self::ffiCall($domainFfi, 'nntmux_cfgd_resolve', null, 0));
        $this->assertSame(19, self::ffiCall($domainFfi, 'nntmux_cfgd_text', 'tmux', 4, 1, $text, 64));
        $this->assertSame('tmux_configurations', FFI::string($text, 19));
        $this->assertSame(-19, self::ffiCall($domainFfi, 'nntmux_cfgd_text', 'tmux', 4, 1, $small, 4));
        $this->assertSame(-1, self::ffiCall($domainFfi, 'nntmux_cfgd_text', 'tmux', 4, 4, $text, 64));
        $this->assertSame(-1, self::ffiCall($domainFfi, 'nntmux_cfgd_text', 'tmux', 4, 1, null, 64));
        $this->assertSame(-1, self::ffiCall($domainFfi, 'nntmux_cfgd_text', null, 4, 1, $text, 64));

        $repoFfi = RustPipelineLibrary::resolveDomain('additional', DomainConfigurationRepository::FFI_CDEF);
        $this->assertNotNull($repoFfi);
        $lens = $repoFfi->new('int64_t[4]');
        $out = $repoFfi->new('int64_t[4]');
        $repoBytes = $repoFfi->new('uint8_t[64]');
        $repoUnaligned = $repoFfi->cast('int64_t*', FFI::addr($repoBytes[1]));
        FFI::memcpy($lens, pack('q*', 1, 1, 1, 1), 32);
        $this->assertSame(2, self::ffiCall($repoFfi, 'nntmux_dcr_unique_strings', 'abab', 4, $lens, 4, $out, 4));
        $this->assertSame([0, 1], array_values(unpack('q2', FFI::string($out, 16)) ?: []));
        $this->assertSame(-1, self::ffiCall($repoFfi, 'nntmux_dcr_unique_strings', 'ababa', 5, $lens, 4, $out, 4));
        $this->assertSame(-1, self::ffiCall($repoFfi, 'nntmux_dcr_unique_strings', 'abab', 4, $lens, 4, $out, 3));
        $this->assertSame(-1, self::ffiCall($repoFfi, 'nntmux_dcr_unique_strings', 'abab', 4, null, 4, $out, 4));
        $this->assertSame(-1, self::ffiCall($repoFfi, 'nntmux_dcr_unique_strings', 'abab', 4, $repoUnaligned, 4, $out, 4));
        $this->assertSame(-1, self::ffiCall($repoFfi, 'nntmux_dcr_unique_strings', 'abab', 4, $lens, 4, $repoUnaligned, 4));
        FFI::memcpy($lens, pack('q*', 5, 5, -1, 5), 32);
        $this->assertSame(2, self::ffiCall($repoFfi, 'nntmux_dcr_unique_ints', $lens, 4, $lens, 4));
        $this->assertSame([5, -1], array_values(unpack('q2', FFI::string($lens, 16)) ?: []));
        $this->assertSame(-1, self::ffiCall($repoFfi, 'nntmux_dcr_unique_ints', $lens, 0, $out, 4));
        $this->assertSame(-1, self::ffiCall($repoFfi, 'nntmux_dcr_unique_ints', null, 4, $out, 4));
        $this->assertSame(-1, self::ffiCall($repoFfi, 'nntmux_dcr_unique_ints', $repoUnaligned, 4, $out, 4));
        $this->assertSame(-1, self::ffiCall($repoFfi, 'nntmux_dcr_unique_ints', $lens, 4, $repoUnaligned, 4));

        $stateFfi = RustPipelineLibrary::resolveDomain('additional', ProcessingRuntimeStateRepository::FFI_CDEF);
        $this->assertNotNull($stateFfi);
        $state = $stateFfi->new('int64_t[3]');
        $stateBytes = $stateFfi->new('uint8_t[64]');
        $stateUnaligned = $stateFfi->cast('int64_t*', FFI::addr($stateBytes[1]));
        $this->assertSame(3, self::ffiCall($stateFfi, 'nntmux_prs_defaults', $state, 3));
        $this->assertSame(-1, self::ffiCall($stateFfi, 'nntmux_prs_defaults', $state, 2));
        $this->assertSame(-1, self::ffiCall($stateFfi, 'nntmux_prs_defaults', null, 3));
        $this->assertSame(-1, self::ffiCall($stateFfi, 'nntmux_prs_defaults', $stateUnaligned, 3));
        FFI::memcpy($state, pack('q*', 3, -1, 0), 24);
        $this->assertSame(0b101, self::ffiCall($stateFfi, 'nntmux_prs_monitor_mask', $state, 3));
        $this->assertSame(-1, self::ffiCall($stateFfi, 'nntmux_prs_monitor_mask', $state, 2));
        $this->assertSame(-1, self::ffiCall($stateFfi, 'nntmux_prs_monitor_mask', null, 3));
        $this->assertSame(-1, self::ffiCall($stateFfi, 'nntmux_prs_monitor_mask', $stateUnaligned, 3));

        $tvFfi = RustPipelineLibrary::resolveDomain('additional', PostProcessService::FFI_CDEF);
        $this->assertNotNull($tvFfi);
        $resolved = $tvFfi->new('int64_t[1]');
        $tvBytes = $tvFfi->new('uint8_t[16]');
        $tvUnaligned = $tvFfi->cast('int64_t*', FFI::addr($tvBytes[1]));
        $this->assertSame(0b11, self::ffiCall($tvFfi, 'nntmux_pps_tv_lookup', 2, 0, ' 2 ', 3, 0, 0, $resolved));
        $this->assertSame(2, $resolved[0]);
        $this->assertSame(0b00, self::ffiCall($tvFfi, 'nntmux_pps_tv_lookup', 0, 0, null, 0, 1, 1, $resolved));
        $this->assertSame(-2, self::ffiCall($tvFfi, 'nntmux_pps_tv_lookup', 2, 0, 'x', 1, 0, 0, $resolved));
        $this->assertSame(-1, self::ffiCall($tvFfi, 'nntmux_pps_tv_lookup', 2, 0, null, 1, 0, 1, $resolved));
        $this->assertSame(-1, self::ffiCall($tvFfi, 'nntmux_pps_tv_lookup', 5, 0, null, 0, 0, 1, $resolved));
        $this->assertSame(-1, self::ffiCall($tvFfi, 'nntmux_pps_tv_lookup', 1, 2, null, 0, 0, 1, null));
        $this->assertSame(-1, self::ffiCall($tvFfi, 'nntmux_pps_tv_lookup', 1, 2, null, 0, 0, 1, $tvUnaligned));
    }

    public function test_native_methods_fall_back_to_php_when_ffi_unavailable(): void
    {
        foreach (self::TOGGLED_CLASSES as $class) {
            $class::setFfiForTesting(false);
            $class::setUseRustFfi(true);
        }

        $this->assertSame('Post Processing', ConfigurationDomain::PostProcessing->label());
        $this->assertSame('tmux_configurations', ConfigurationDomain::Tmux->tableNative());
        $this->assertSame(ConfigurationDomain::Site, ConfigurationDomain::tryFromNative('site'));
        $this->assertNull(ConfigurationDomain::tryFromNative('nope'));
        $this->assertNull(ConfigurationDomain::Site->textNative(ConfigurationDomain::TEXT_LABEL));

        $this->assertSame('configuration:metadata', ConfigurationProvider::cacheKeyForNative(ConfigurationDomain::Metadata));
        $this->assertSame('site_configurations', ConfigurationProvider::tableFor(ConfigurationDomain::Site));

        $this->assertSame(TmuxConfiguration::class, DomainConfigurationRepository::modelClassForNative(ConfigurationDomain::Tmux));
        $this->assertSame(DomainConfigurationRepository::cleanupRuleRowsPhp(['a', 'a']), DomainConfigurationRepository::cleanupRuleRowsNative(['a', 'a']));
        $this->assertSame(DomainConfigurationRepository::colorExclusionRowsPhp([3, 3]), DomainConfigurationRepository::colorExclusionRows([3, 3]));

        $this->assertSame(ProcessingRuntimeStateRepository::defaultStateAttributesPhp(), ProcessingRuntimeStateRepository::defaultStateAttributesNative());
        $this->assertSame(ProcessingRuntimeStateRepository::projectMonitorPathsPhp('a', null, ''), ProcessingRuntimeStateRepository::projectMonitorPathsNative('a', null, ''));

        $calls = 0;
        $this->assertSame(['resolved' => 2, 'renamedOnly' => true], PostProcessService::resolveTvLookupModeNative('x', static function () use (&$calls): int {
            $calls++;

            return 2;
        }));
        $this->assertSame(1, $calls);
        $this->assertSame(['resolved' => 1, 'renamedOnly' => false], PostProcessService::resolveTvLookupMode(' 1 ', 2));
    }

    // ---------------------------------------------------------------------
    // Paired Randomized Benchmarks
    // ---------------------------------------------------------------------

    public function test_paired_randomized_benchmarks(): void
    {
        if (getenv('NNTMUX_CONFIG_DOMAIN_RUNTIME_POSTPROCESS_BENCH') !== '1') {
            $this->markTestSkipped('Set NNTMUX_CONFIG_DOMAIN_RUNTIME_POSTPROCESS_BENCH=1 to run paired Rust/PHP benchmarks.');
        }

        $commonRules = ['backfill', 'update_binaries', 'backfill', 'postprocess'];
        $uncommonRules = [];
        for ($i = 0; $i < 400; $i++) {
            $uncommonRules[] = 'cleanup-rule-'.($i % 150).str_repeat('x', $i % 40);
        }
        $commonColors = [0, 7, 15, 0];
        $uncommonColors = array_merge(range(255, 0, -1), range(0, 255), range(0, 255, 3));

        $workloads = [
            'ConfigurationDomain::label' => [
                ConfigurationDomain::class,
                static fn () => ConfigurationDomain::Site->label(),
                static fn () => ConfigurationDomain::PostProcessing->label(),
            ],
            'ConfigurationDomain::table' => [
                ConfigurationDomain::class,
                static fn () => ConfigurationDomain::Tmux->table(),
                static fn () => ConfigurationDomain::Registration->table(),
            ],
            'ConfigurationProvider::cacheKeyFor' => [
                ConfigurationProvider::class,
                static fn () => ConfigurationProvider::cacheKeyFor(ConfigurationDomain::Site),
                static fn () => ConfigurationProvider::cacheKeyFor(ConfigurationDomain::PostProcessing),
            ],
            'ConfigurationProvider::tableFor' => [
                ConfigurationProvider::class,
                static fn () => ConfigurationProvider::tableFor(ConfigurationDomain::Tmux),
                static fn () => ConfigurationProvider::tableFor(ConfigurationDomain::Metadata),
            ],
            'DomainConfigurationRepository::modelClassFor' => [
                DomainConfigurationRepository::class,
                static fn () => DomainConfigurationRepository::modelClassFor(ConfigurationDomain::Tmux),
                static fn () => DomainConfigurationRepository::modelClassFor(ConfigurationDomain::PostProcessing),
            ],
            'DomainConfigurationRepository::cleanupRuleRows' => [
                DomainConfigurationRepository::class,
                static fn () => DomainConfigurationRepository::cleanupRuleRows($commonRules),
                static fn () => DomainConfigurationRepository::cleanupRuleRows($uncommonRules),
            ],
            'DomainConfigurationRepository::colorExclusionRows' => [
                DomainConfigurationRepository::class,
                static fn () => DomainConfigurationRepository::colorExclusionRows($commonColors),
                static fn () => DomainConfigurationRepository::colorExclusionRows($uncommonColors),
            ],
            'ProcessingRuntimeStateRepository::projectMonitorPaths' => [
                ProcessingRuntimeStateRepository::class,
                static fn () => ProcessingRuntimeStateRepository::projectMonitorPaths(null, null, null),
                static fn () => ProcessingRuntimeStateRepository::projectMonitorPaths('/var/tmp/nntmux/monitor', '', str_repeat('/deep', 400)),
            ],
            'ProcessingRuntimeStateRepository::defaultStateAttributes' => [
                ProcessingRuntimeStateRepository::class,
                static fn () => ProcessingRuntimeStateRepository::defaultStateAttributes(),
                static fn () => ProcessingRuntimeStateRepository::defaultStateAttributes()['id'],
            ],
            'PostProcessService::resolveTvLookupMode' => [
                PostProcessService::class,
                static fn () => PostProcessService::resolveTvLookupMode('', static fn (): int => 1),
                static fn () => PostProcessService::resolveTvLookupMode(" \t200E-2\n", static fn (): int => 1),
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

                    $stats = $this->runPairedBenchmark($class, $fn, 200, 41, 2003 + $rep);
                    fwrite(STDERR, json_encode(['method' => $name, 'workload' => $kind, 'repetition' => $rep] + $stats).PHP_EOL);
                    $worst["{$kind}_mean"] = min($worst["{$kind}_mean"], $stats['mean_speedup']);
                    $worst["{$kind}_p99"] = min($worst["{$kind}_p99"], $stats['p99_speedup']);
                }
            }
            $class::setUseRustFfi(null);
            $qualified = min($worst) >= 1.5;
            $rows[] = sprintf(
                ' * | %-56s | %.2fx / %.2fx       | %.2fx / %.2fx         | %-7s | %-12s |',
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
            $class::setUseRustFfi($toggle);
            for ($w = 0; $w < 50; $w++) {
                $fn();
            }
        }

        $times = [true => [], false => []];
        mt_srand($seed);
        for ($s = 0; $s < $samples; $s++) {
            $order = mt_rand(0, 1) === 0 ? [true, false] : [false, true];
            foreach ($order as $toggle) {
                $class::setUseRustFfi($toggle);
                $t0 = hrtime(true);
                for ($i = 0; $i < $innerLoops; $i++) {
                    $fn();
                }
                $times[$toggle][] = (hrtime(true) - $t0) / 1000.0 / $innerLoops;
            }
        }

        $rust = $times[true];
        $php = $times[false];
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
