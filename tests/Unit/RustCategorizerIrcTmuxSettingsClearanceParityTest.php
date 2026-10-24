<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\ConfigurationDomain;
use App\Http\Middleware\ClearanceMiddleware;
use App\Models\Category;
use App\Services\Categorization\CategorizationResult;
use App\Services\Categorization\Categorizers\AbstractCategorizer;
use App\Services\Categorization\ReleaseContext;
use App\Services\Predb\Irc\IrcNetworks;
use App\Services\Tmux\TmuxCommand;
use App\Support\Configuration\SettingsPageCatalog;
use App\Support\RustPipelineLibrary;
use FFI;
use InvalidArgumentException;
use Random\Engine\Mt19937;
use Random\Randomizer;
use ReflectionMethod;
use ReflectionProperty;
use Tests\TestCase;

/**
 * Wave 18 Worker 6 parity and paired benchmark suite for `AbstractCategorizer`,
 * `IrcNetworks`, `TmuxCommand`, `SettingsPageCatalog`, and `ClearanceMiddleware`
 * (`rust/pipeline-ffi/src/media.rs`).
 *
 * Reproduce paired randomized benchmarks:
 *   NNTMUX_CATEGORIZER_IRC_TMUX_SETTINGS_CLEARANCE_BENCH=1 php artisan test --compact tests/Unit/RustCategorizerIrcTmuxSettingsClearanceParityTest.php --filter=test_benchmark_paired_workloads
 *
 * Protocol: seed 20261024; 512 warmups; 3 repetitions of 4,096 randomized paired
 * single calls per workload (common and uncommon); nearest-rank p99; output equality
 * verified before timing. Adoption requires >= 1.5x speedup on all 4 metrics
 * (common mean, common p99, uncommon mean, uncommon p99) per `.ai/rules/rust-performance.md`.
 *
 * Paired speedup results (`PHP latency / Rust FFI latency`, mean/p99 across 3 reps):
 * - `categorizerGetPriority`:    common 0.16x/0.19x, 0.16x/0.16x, 0.16x/0.21x | uncommon 0.17x/0.17x, 0.16x/0.20x, 0.12x/0.10x
 * - `categorizerShouldSkip`:     common 0.15x/0.11x, 0.18x/0.08x, 0.18x/0.18x | uncommon 0.18x/0.07x, 0.16x/0.12x, 0.16x/0.14x
 * - `categorizerMatched`:        common 0.22x/0.08x, 0.22x/0.08x, 0.23x/0.11x | uncommon 0.23x/0.09x, 0.24x/0.13x, 0.23x/0.06x
 * - `categorizerNoMatch`:        common 0.24x/0.21x, 0.25x/0.22x, 0.25x/0.21x | uncommon 0.25x/0.22x, 0.27x/0.23x, 0.24x/0.13x
 * - `ircNetworksEnabled`:        common 0.68x/0.78x, 0.67x/0.48x, 0.65x/0.62x | uncommon 0.22x/0.13x, 0.21x/0.11x, 0.21x/0.17x
 * - `ircNetworksResolve`:        common 0.53x/0.40x, 0.46x/0.33x, 0.58x/0.64x | uncommon 0.83x/0.89x, 0.85x/0.87x, 0.76x/0.82x
 * - `ircNetworksLegacyChannels`: common 0.34x/0.23x, 0.32x/0.13x, 0.28x/0.17x | uncommon 0.32x/0.14x, 0.31x/0.16x, 0.32x/0.24x
 * - `tmuxArguments`:             common 0.16x/0.07x, 0.14x/0.08x, 0.14x/0.06x | uncommon 0.18x/0.12x, 0.16x/0.08x, 0.15x/0.04x
 * - `tmuxMonitor`:               common 0.16x/0.03x, 0.16x/0.04x, 0.16x/0.04x | uncommon 0.15x/0.03x, 0.14x/0.03x, 0.17x/0.03x
 * - `settingsWritableColumns`:   common 0.77x/0.79x, 0.77x/0.69x, 0.83x/0.80x | uncommon 1.34x/1.15x, 1.30x/1.14x, 1.36x/1.23x
 * - `settingsField`:             common 1.22x/1.13x, 1.21x/1.29x, 1.23x/1.04x | uncommon 3.53x/1.94x, 3.79x/1.94x, 3.58x/2.22x
 * - `settingsOptions`:           common 0.68x/0.37x, 0.63x/0.56x, 0.88x/0.54x | uncommon 0.09x/0.03x, 0.09x/0.04x, 0.09x/0.03x
 * - `settingsIsInteger`:         common 1.01x/0.92x, 1.01x/0.66x, 0.96x/0.92x | uncommon 1.07x/0.67x, 1.11x/1.10x, 1.05x/1.00x
 * - `settingsHelp`:              common 0.15x/0.04x, 0.13x/0.07x, 0.16x/0.08x | uncommon 2.27x/3.23x, 2.28x/2.80x, 2.22x/2.45x
 * - `settingsUnit`:              common 0.17x/0.20x, 0.20x/0.20x, 0.19x/0.20x | uncommon 0.31x/0.36x, 0.28x/0.37x, 0.38x/0.31x
 * - `settingsCleanupRules`:      common 0.06x/0.10x, 0.06x/0.06x, 0.06x/0.02x | uncommon 0.06x/0.10x, 0.05x/0.06x, 0.07x/0.06x
 * - `clearanceMatchesPath`:      common 0.65x/0.57x, 0.65x/0.57x, 0.63x/0.67x | uncommon 0.76x/0.78x, 0.83x/0.86x, 0.84x/0.86x
 * - `clearanceMainCategory`:     common 0.28x/0.33x, 0.28x/0.38x, 0.29x/0.32x | uncommon 0.30x/0.33x, 0.34x/0.33x, 0.30x/0.31x
 *
 * While `settingsField` and `settingsHelp` achieve 2.2x-3.8x speedups on `Str::headline`-heavy
 * uncommon workloads, no method exceeds 1.5x across all four required metrics (common mean/p99
 * and uncommon mean/p99) due to single-call FFI boundary marshaling overhead. Per
 * `.ai/rules/rust-performance.md`, `$useRustFfi = false` is retained as the default across all
 * five classes while preserving both `*Native` and `*Php` implementations.
 */
final class RustCategorizerIrcTmuxSettingsClearanceParityTest extends TestCase
{
    private const string FULL_CDEF = AbstractCategorizer::FFI_CDEF."\n"
        .IrcNetworks::FFI_CDEF."\n"
        .TmuxCommand::FFI_CDEF."\n"
        .SettingsPageCatalog::FFI_CDEF."\n"
        .ClearanceMiddleware::FFI_CDEF;

    protected function tearDown(): void
    {
        AbstractCategorizer::setUseRustFfi(false);
        IrcNetworks::setUseRustFfi(false);
        TmuxCommand::setUseRustFfi(false);
        SettingsPageCatalog::setUseRustFfi(false);
        ClearanceMiddleware::setUseRustFfi(false);
        parent::tearDown();
    }

    private static function ffi(): FFI
    {
        $ffi = RustPipelineLibrary::resolveDomain('media', self::FULL_CDEF);
        self::assertNotNull($ffi, 'Expected libnntmux_pipeline media domain FFI to be available.');

        return $ffi;
    }

    private static function makeCategorizer(int $priority = 50): AbstractCategorizer
    {
        return new class($priority) extends AbstractCategorizer
        {
            public function __construct(int $priority)
            {
                $this->priority = $priority;
            }

            public function getName(): string
            {
                return 'StubCategorizer';
            }

            public function categorize(ReleaseContext $context): CategorizationResult
            {
                return $this->noMatch();
            }

            /**
             * @param  array<string, mixed>  $debug
             */
            public function callMatched(int $categoryId, float $confidence, string $matchedBy, array $debug = []): CategorizationResult
            {
                return $this->matched($categoryId, $confidence, $matchedBy, $debug);
            }

            public function callNoMatch(): CategorizationResult
            {
                return $this->noMatch();
            }
        };
    }

    public function test_abstract_categorizer_priority_skip_matched_and_no_match_match_php(): void
    {
        self::ffi();

        foreach ([1, 10, 50, 100, -5, 0] as $prio) {
            $categorizer = self::makeCategorizer($prio);
            $expectedPriority = $categorizer->getPriorityPhp();
            self::assertSame($expectedPriority, $categorizer->getPriorityNative());

            foreach ([false, true] as $toggle) {
                AbstractCategorizer::setUseRustFfi($toggle);
                self::assertSame($expectedPriority, $categorizer->getPriority());
            }
        }

        $categorizer = self::makeCategorizer(35);
        $contexts = [
            new ReleaseContext('The.Matrix.1999.1080p.BluRay.x264-GROUP', 100, 'alt.binaries.movies.mkv', 'poster@example.com'),
            new ReleaseContext('', 0, '', ''),
            new ReleaseContext(' XXX.Adult.Release.1080p ', 200, 'alt.binaries.erotica', 'poster'),
        ];

        foreach ($contexts as $context) {
            $expectedSkip = $categorizer->shouldSkipPhp($context);
            self::assertSame($expectedSkip, $categorizer->shouldSkipNative($context));

            foreach ([false, true] as $toggle) {
                AbstractCategorizer::setUseRustFfi($toggle);
                self::assertSame($expectedSkip, $categorizer->shouldSkip($context));
            }
        }

        $matchCases = [
            [Category::MOVIE_HD, 0.9, 'movie_hd_bluray', ['rule' => 'bluray']],
            [Category::TV_UHD, 0.95, 'tv_uhd_2160p', []],
            [Category::OTHER_MISC, 0.0, '', ['empty' => true]],
            [Category::PC_GAMES, 0.75, str_repeat('long_matched_by_', 20), ['len' => 320]],
            [Category::MOVIE_OTHER, NAN, 'nan_confidence_fallback', []],
        ];

        foreach ($matchCases as [$catId, $conf, $matchedBy, $debug]) {
            $expected = $categorizer->matchedPhp($catId, $conf, $matchedBy, $debug);
            $native = $categorizer->matchedNative($catId, $conf, $matchedBy, $debug);

            self::assertSame($expected->categoryId, $native->categoryId);
            if (is_nan($conf)) {
                self::assertTrue(is_nan($native->confidence));
            } else {
                self::assertSame($expected->confidence, $native->confidence);
            }
            self::assertSame($expected->matchedBy, $native->matchedBy);
            self::assertSame($expected->debug, $native->debug);

            foreach ([false, true] as $toggle) {
                AbstractCategorizer::setUseRustFfi($toggle);
                /** @var CategorizationResult $dispatched */
                $dispatched = (new ReflectionMethod(AbstractCategorizer::class, 'matched'))
                    ->invoke($categorizer, $catId, $conf, $matchedBy, $debug);
                self::assertSame($expected->categoryId, $dispatched->categoryId);
                self::assertSame($expected->matchedBy, $dispatched->matchedBy);
                self::assertSame($expected->debug, $dispatched->debug);
            }
        }

        $expectedNoMatch = $categorizer->noMatchPhp();
        $nativeNoMatch = $categorizer->noMatchNative();
        self::assertSame($expectedNoMatch->categoryId, $nativeNoMatch->categoryId);
        self::assertSame($expectedNoMatch->confidence, $nativeNoMatch->confidence);
        self::assertSame($expectedNoMatch->matchedBy, $nativeNoMatch->matchedBy);
        self::assertSame($expectedNoMatch->debug, $nativeNoMatch->debug);

        foreach ([false, true] as $toggle) {
            AbstractCategorizer::setUseRustFfi($toggle);
            /** @var CategorizationResult $dispatched */
            $dispatched = (new ReflectionMethod(AbstractCategorizer::class, 'noMatch'))
                ->invoke($categorizer);
            self::assertSame($expectedNoMatch->categoryId, $dispatched->categoryId);
            self::assertSame($expectedNoMatch->confidence, $dispatched->confidence);
            self::assertSame($expectedNoMatch->matchedBy, $dispatched->matchedBy);
        }
    }

    public function test_irc_networks_enabled_resolve_and_legacy_channels_match_php(): void
    {
        self::ffi();

        $networkConfigs = [
            [],
            [
                'synirc' => ['enabled' => true],
                'corruptnet' => ['enabled' => true],
                'zenet' => ['enabled' => false],
                'predatabase' => ['enabled' => 1],
                'efnet' => ['enabled' => 0],
                'invalid_entry' => 'not-an-array',
            ],
            [
                'synirc' => ['enabled' => false],
                'zenet' => ['enabled' => false],
            ],
            [
                '' => ['enabled' => true],
                'with_null' => ['enabled' => null],
            ],
        ];

        foreach ($networkConfigs as $idx => $netMap) {
            config(['irc_settings.networks' => $netMap]);
            $expected = IrcNetworks::enabledPhp();
            self::assertSame($expected, IrcNetworks::enabledNative(), "enabled case {$idx}");

            foreach ([false, true] as $toggle) {
                IrcNetworks::setUseRustFfi($toggle);
                self::assertSame($expected, IrcNetworks::enabled());
            }
        }

        $legacyChannelInputs = [
            null,
            false,
            '',
            '0',
            serialize([]),
            serialize(['#PreNNTmux' => null]),
            serialize(['#PreNNTmux' => null, '#private' => 'pw', '#empty_pw' => '']),
            serialize(['#a.b.teevee' => null, '#a.b.moovee' => 'secret123', '#pre' => null]),
            'not-valid-serialized-payload',
            serialize([0 => '#numeric_key_fallback']),
        ];

        foreach ($legacyChannelInputs as $idx => $raw) {
            config(['irc_settings.scrape_irc_channels' => $raw]);
            $expected = IrcNetworks::legacyChannelsPhp();
            self::assertSame($expected, IrcNetworks::legacyChannelsNative(), "legacyChannels case {$idx}");

            foreach ([false, true] as $toggle) {
                IrcNetworks::setUseRustFfi($toggle);
                self::assertSame($expected, IrcNetworks::legacyChannels());
            }
        }

        // Restore full default IRC network config and test resolve() across all networks
        $defaultIrcSettings = require base_path('config/irc_settings.php');
        config(['irc_settings' => $defaultIrcSettings]);
        config([
            'irc_settings.scrape_irc_server' => 'irc.synirc.example',
            'irc_settings.scrape_irc_port' => 7001,
            'irc_settings.scrape_irc_tls' => true,
            'irc_settings.scrape_irc_channels' => serialize(['#PreNNTmux' => null, '#private' => 'pw']),
        ]);

        $networkKeys = array_keys((array) config('irc_settings.networks', []));
        self::assertNotEmpty($networkKeys);

        foreach ($networkKeys as $key) {
            $expectedProfile = IrcNetworks::resolvePhp($key);
            self::assertSame($expectedProfile, IrcNetworks::resolveNative($key), "resolve network={$key}");

            foreach ([false, true] as $toggle) {
                IrcNetworks::setUseRustFfi($toggle);
                self::assertSame($expectedProfile, IrcNetworks::resolve($key));
            }
        }

        // Custom network edge cases (empty password normalized to null, tls_verify_peer_name true/false/null)
        config([
            'irc_settings.networks.custom_irc' => [
                'enabled' => true,
                'format' => 'corruptnet',
                'server' => 'irc.custom.example',
                'port' => 6697,
                'tls' => true,
                'tls_verify_peer_name' => true,
                'channels' => ['#pre' => '', '#secret' => 'pass123', '#open' => null],
            ],
        ]);
        $expectedCustom = IrcNetworks::resolvePhp('custom_irc');
        self::assertSame(['#pre' => null, '#secret' => 'pass123', '#open' => null], $expectedCustom['channels']);
        self::assertSame($expectedCustom, IrcNetworks::resolveNative('custom_irc'));

        foreach ([false, true] as $toggle) {
            IrcNetworks::setUseRustFfi($toggle);
            try {
                IrcNetworks::resolve('unknown_network_key');
                self::fail('Expected InvalidArgumentException for unknown IRC network.');
            } catch (InvalidArgumentException $e) {
                self::assertStringContainsString('Unknown IRC network [unknown_network_key]', $e->getMessage());
            }
        }
    }

    public function test_tmux_command_arguments_and_monitor_match_php(): void
    {
        self::ffi();

        $sockets = ['', 'nntmux', 'custom-tmux.sock', "socket\0with_nul"];
        $argLists = [
            [],
            ['has-session', '-t', '=nntmux'],
            ['list-panes', '-s', '-t', 'nntmux:0', '-F', "#{window_index}:#{pane_index}\t#{pane_title}"],
            ['new-window', '-P', '-F', '#{pane_id}', '-t', 'nntmux:1', '-n', 'work', 'sleep', '86400'],
            ['send-keys', "arg\0with_nul"],
        ];

        foreach ($sockets as $socket) {
            config(['tmux.socket_name' => $socket]);
            foreach ($argLists as $args) {
                $expected = TmuxCommand::argumentsPhp($args);
                self::assertSame($expected, TmuxCommand::argumentsNative($args));

                foreach ([false, true] as $toggle) {
                    TmuxCommand::setUseRustFfi($toggle);
                    self::assertSame($expected, TmuxCommand::arguments($args));
                }
            }
        }

        $sessions = ['nntmux', 'monitor-recovery', '', 'session_with-dashes.1', "bad\0session"];
        foreach ($sessions as $session) {
            $expected = TmuxCommand::monitorPhp($session);
            self::assertSame($expected, TmuxCommand::monitorNative($session));

            foreach ([false, true] as $toggle) {
                TmuxCommand::setUseRustFfi($toggle);
                self::assertSame($expected, TmuxCommand::monitor($session));
            }
        }
    }

    public function test_settings_page_catalog_methods_match_php_across_all_domains(): void
    {
        self::ffi();
        $catalog = new SettingsPageCatalog;

        $expectedCleanup = SettingsPageCatalog::cleanupRulesPhp();
        self::assertSame($expectedCleanup, SettingsPageCatalog::cleanupRulesNative());
        foreach ([false, true] as $toggle) {
            SettingsPageCatalog::setUseRustFfi($toggle);
            self::assertSame($expectedCleanup, SettingsPageCatalog::cleanupRules());
        }

        foreach (ConfigurationDomain::cases() as $domain) {
            $expectedWritable = $catalog->writableColumnsPhp($domain);
            self::assertSame($expectedWritable, $catalog->writableColumnsNative($domain), "writableColumns {$domain->value}");

            foreach ([false, true] as $toggle) {
                SettingsPageCatalog::setUseRustFfi($toggle);
                self::assertSame($expectedWritable, $catalog->writableColumns($domain));
            }

            SettingsPageCatalog::setUseRustFfi(false);
            $phpFields = $catalog->fields($domain);
            $phpPageFields = $catalog->fieldsForPage($domain);

            SettingsPageCatalog::setUseRustFfi(true);
            $rustFields = $catalog->fields($domain);
            $rustPageFields = $catalog->fieldsForPage($domain);

            self::assertEquals($phpFields, $rustFields, "fields {$domain->value}");
            self::assertEquals($phpPageFields, $rustPageFields, "fieldsForPage {$domain->value}");

            foreach ($phpFields as $fieldObj) {
                $col = $fieldObj->column;
                self::assertSame($catalog->optionsPhp($col), $catalog->optionsNative($col), "options {$col}");
                self::assertSame($catalog->isIntegerPhp($domain, $col), $catalog->isIntegerNative($domain, $col), "isInteger {$col}");
                self::assertSame($catalog->helpPhp($col), $catalog->helpNative($col), "help {$col}");
                self::assertSame($catalog->unitPhp($col), $catalog->unitNative($col), "unit {$col}");
                self::assertEquals($catalog->fieldPhp($domain, $col), $catalog->fieldNative($domain, $col), "field {$col}");
            }
        }

        // Additional synthetic column names testing headline/unit/options/fallback paths
        $extraColumns = [
            'custom_timeout_seconds',
            'custom_retention_hours',
            'custom_retention_days',
            'custom_sleep_milliseconds',
            'custom_poll_timer',
            'custom_lookup',
            'camelCaseSettingName',
            'kebab-case-setting',
            'foo_0_bar',
            'non_ascii_étoile_setting',
        ];

        foreach ($extraColumns as $col) {
            self::assertSame($catalog->optionsPhp($col), $catalog->optionsNative($col), "extra options {$col}");
            self::assertSame($catalog->isIntegerPhp(ConfigurationDomain::Site, $col), $catalog->isIntegerNative(ConfigurationDomain::Site, $col), "extra isInteger {$col}");
            self::assertSame($catalog->helpPhp($col), $catalog->helpNative($col), "extra help {$col}");
            self::assertSame($catalog->unitPhp($col), $catalog->unitNative($col), "extra unit {$col}");
            self::assertEquals($catalog->fieldPhp(ConfigurationDomain::Site, $col), $catalog->fieldNative(ConfigurationDomain::Site, $col), "extra field {$col}");
        }
    }

    public function test_clearance_middleware_path_matching_and_category_permissions_match_php(): void
    {
        self::ffi();
        $middleware = new ClearanceMiddleware;

        $pathCases = [
            ['Movies', 'Movies'],
            ['movies', 'Movies'],
            ['MOVIES/HD', 'movies'],
            ['Movies/UHD/Remux', 'Movies'],
            ['browse/Movies', 'Movies'],
            ['BROWSE/MOVIES/HD', 'movies'],
            ['browse/tv/sd', 'TV'],
            ['trending-movies', 'trending-movies'],
            ['trending-movies/top', 'trending-movies'],
            ['movies_extra', 'Movies'],
            ['browses/Movies', 'Movies'],
            ['browse/Movies_extra', 'Movies'],
            ['admin/Movies', 'Movies'],
            ['', 'Movies'],
            ['Movies', ''],
            ['', ''],
        ];

        foreach ($pathCases as [$path, $cat]) {
            $expected = $middleware->matchesCategoryPathPhp($path, $cat);
            self::assertSame($expected, $middleware->matchesCategoryPathNative($path, $cat), "path={$path} cat={$cat}");

            foreach ([false, true] as $toggle) {
                ClearanceMiddleware::setUseRustFfi($toggle);
                self::assertSame($expected, $middleware->matchesCategoryPath($path, $cat));
            }
        }

        $categories = ['movies', 'Movies', 'CONSOLE', 'books', 'Audio', 'xxx', 'XXX', 'games', 'PC', 'tv', 'TV', 'other', 'anime', ''];
        foreach ([true, false] as $allowed) {
            $user = new class($allowed)
            {
                /** @var list<string> */
                public array $checked = [];

                public function __construct(private readonly bool $allowed) {}

                public function hasDirectPermission(string $permission): bool
                {
                    $this->checked[] = $permission;

                    return $this->allowed;
                }
            };

            foreach ($categories as $catName) {
                $userPhp = clone $user;
                $userNative = clone $user;

                $expectedBlocked = $middleware->checkMainCategoryPermissionPhp($userPhp, $catName);
                $actualBlocked = $middleware->checkMainCategoryPermissionNative($userNative, $catName);

                self::assertSame($expectedBlocked, $actualBlocked, "cat={$catName} allowed=".($allowed ? '1' : '0'));
                self::assertSame($userPhp->checked, $userNative->checked, "checked permissions for cat={$catName}");

                foreach ([false, true] as $toggle) {
                    ClearanceMiddleware::setUseRustFfi($toggle);
                    $userDispatched = clone $user;
                    self::assertSame($expectedBlocked, $middleware->checkMainCategoryPermission($userDispatched, $catName));
                    self::assertSame($userPhp->checked, $userDispatched->checked);
                }
            }
        }
    }

    public function test_direct_c_abi_null_alignment_overflow_and_small_buffer_contracts(): void
    {
        $ffi = self::ffi();
        $small = $ffi->new('char[4]');
        $outI64 = $ffi->new('int64_t');
        $outF64 = $ffi->new('double');
        $outI32 = $ffi->new('int32_t');

        // Construct unaligned pointers (offset by 1 byte inside a 16-byte buffer)
        $rawBytes = $ffi->new('uint8_t[16]');
        $unalignedI64 = $ffi->cast('int64_t*', FFI::addr($rawBytes[1]));
        $unalignedF64 = $ffi->cast('double*', FFI::addr($rawBytes[1]));
        $unalignedI32 = $ffi->cast('int32_t*', FFI::addr($rawBytes[1]));

        // 1. AbstractCategorizer C-ABI checks
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_abstract_categorizer_priority_and_skip(50, null, 1, FFI::addr($outI64)));
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_abstract_categorizer_priority_and_skip(50, '', 0, null));
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_abstract_categorizer_priority_and_skip(50, '', 0, $unalignedI64));

        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_abstract_categorizer_format_result_meta(0, 10, NAN, 'x', 1, FFI::addr($outI64), FFI::addr($outF64), $small, 4));
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_abstract_categorizer_format_result_meta(0, 10, 0.5, 'x', 1, $unalignedI64, FFI::addr($outF64), $small, 4));
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_abstract_categorizer_format_result_meta(0, 10, 0.5, 'x', 1, FFI::addr($outI64), $unalignedF64, $small, 4));
        // @phpstan-ignore method.notFound
        $needMeta = $ffi->nntmux_abstract_categorizer_format_result_meta(1, 10, 0.0, '', 0, FFI::addr($outI64), FFI::addr($outF64), $small, 4);
        self::assertSame(-8, $needMeta);

        // 2. IrcNetworks C-ABI checks
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_irc_networks_filter_enabled_nul('a', 1, '1', 1, $unalignedI64, $small, 4));
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_irc_networks_filter_enabled_nul('a', 1, '11', 2, FFI::addr($outI64), $small, 4));
        // @phpstan-ignore method.notFound
        $needNet = $ffi->nntmux_irc_networks_filter_enabled_nul("synirc\0corruptnet", 17, '11', 2, FFI::addr($outI64), $small, 4);
        self::assertSame(-17, $needNet);

        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_irc_networks_parse_legacy_channels('', 0, $unalignedI64, $small, 4));
        // @phpstan-ignore method.notFound
        $needLeg = $ffi->nntmux_irc_networks_parse_legacy_channels('', 0, FFI::addr($outI64), $small, 4);
        self::assertSame(-12, $needLeg);

        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_irc_networks_resolve_profile('synirc', 6, 0, '', 0, 0, '', 0, 0, '', 0, 1, 'irc.test', 8, 1, 6667, 1, 0, 0, 0, 0, '', 0, '', 0, 0, $unalignedI64, FFI::addr($outI32), FFI::addr($outI64), $small, 4));
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_irc_networks_resolve_profile('synirc', 6, 0, '', 0, 0, '', 0, 0, '', 0, 1, 'irc.test', 8, 1, 6667, 1, 0, 0, 0, 0, '', 0, '', 0, 0, FFI::addr($outI64), $unalignedI32, FFI::addr($outI64), $small, 4));

        // 3. TmuxCommand C-ABI checks
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_tmux_command_arguments_nul('', 0, '', 0, 0, $unalignedI64, $small, 4));
        // @phpstan-ignore method.notFound
        $needTmux = $ffi->nntmux_tmux_command_arguments_nul('sock', 4, 'list-panes', 10, 1, FFI::addr($outI64), $small, 4);
        self::assertLessThan(-4, $needTmux);
        // @phpstan-ignore method.notFound
        $needMon = $ffi->nntmux_tmux_command_monitor_nul('/usr/bin/php', 12, '/app/artisan', 12, 'main', 4, $small, 4);
        self::assertLessThan(-4, $needMon);

        // 4. SettingsPageCatalog C-ABI checks
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_settings_catalog_writable_columns_nul('site', 4, $unalignedI64, $small, 4));
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_settings_catalog_writable_columns_nul('unknown', 7, FFI::addr($outI64), $small, 4));
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_settings_catalog_cleanup_rules_nul($unalignedI64, $small, 4));
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_settings_catalog_options_nul('status', 6, $unalignedI64, $small, 4));
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_settings_catalog_is_integer('invalid', 7, 'title', 5));
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_settings_catalog_field_nul('site', 4, 'title', 5, $unalignedI64, FFI::addr($outI64), FFI::addr($outI32), $small, 4));
        // @phpstan-ignore method.notFound
        $needField = $ffi->nntmux_settings_catalog_field_nul('site', 4, 'title', 5, FFI::addr($outI64), FFI::addr($outI64), FFI::addr($outI32), $small, 4);
        self::assertLessThan(-4, $needField);

        // 5. ClearanceMiddleware C-ABI checks
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_clearance_matches_category_path(null, 3, 'tv', 2));
        // @phpstan-ignore method.notFound
        $needClearance = $ffi->nntmux_clearance_resolve_main_category('movies', 6, $small, 4);
        self::assertSame(-18, $needClearance);
    }

    public function test_php_fallback_when_ffi_handle_is_unavailable(): void
    {
        $classes = [
            AbstractCategorizer::class,
            IrcNetworks::class,
            TmuxCommand::class,
            SettingsPageCatalog::class,
            ClearanceMiddleware::class,
        ];

        $saved = [];
        foreach ($classes as $class) {
            $prop = new ReflectionProperty($class, 'ffi');
            $saved[$class] = $prop->getValue();
            $prop->setValue(null, false);
        }

        try {
            $categorizer = self::makeCategorizer(42);
            $ctx = new ReleaseContext('Test.Release', 1, 'alt.binaries.test', 'poster');
            self::assertSame(42, $categorizer->getPriorityNative());
            self::assertFalse($categorizer->shouldSkipNative($ctx));
            self::assertSame(2040, $categorizer->matchedNative(2040, 0.8, 'rule')->categoryId);
            self::assertSame('no_match', $categorizer->noMatchNative()->matchedBy);

            self::assertSame(IrcNetworks::enabledPhp(), IrcNetworks::enabledNative());
            self::assertSame(IrcNetworks::legacyChannelsPhp(), IrcNetworks::legacyChannelsNative());
            self::assertSame(IrcNetworks::resolvePhp('synirc'), IrcNetworks::resolveNative('synirc'));

            self::assertSame(TmuxCommand::argumentsPhp(['list-panes']), TmuxCommand::argumentsNative(['list-panes']));
            self::assertSame(TmuxCommand::monitorPhp('nntmux'), TmuxCommand::monitorNative('nntmux'));

            $catalog = new SettingsPageCatalog;
            self::assertSame($catalog->writableColumnsPhp(ConfigurationDomain::Site), $catalog->writableColumnsNative(ConfigurationDomain::Site));
            self::assertEquals($catalog->fieldPhp(ConfigurationDomain::Site, 'title'), $catalog->fieldNative(ConfigurationDomain::Site, 'title'));
            self::assertSame($catalog->optionsPhp('status'), $catalog->optionsNative('status'));
            self::assertSame($catalog->isIntegerPhp(ConfigurationDomain::Site, 'trailers_size_x'), $catalog->isIntegerNative(ConfigurationDomain::Site, 'trailers_size_x'));
            self::assertSame($catalog->helpPhp('title'), $catalog->helpNative('title'));
            self::assertSame($catalog->unitPhp('timeout_seconds'), $catalog->unitNative('timeout_seconds'));
            self::assertSame(SettingsPageCatalog::cleanupRulesPhp(), SettingsPageCatalog::cleanupRulesNative());

            $clearance = new ClearanceMiddleware;
            self::assertTrue($clearance->matchesCategoryPathNative('browse/Movies/HD', 'Movies'));
            $stubUser = new class
            {
                public function hasDirectPermission(string $permission): bool
                {
                    return false;
                }
            };
            self::assertSame('Movies', $clearance->checkMainCategoryPermissionNative($stubUser, 'Movies'));
        } finally {
            foreach ($classes as $class) {
                $prop = new ReflectionProperty($class, 'ffi');
                $prop->setValue(null, $saved[$class]);
            }
        }
    }

    /**
     * Run with:
     * NNTMUX_CATEGORIZER_IRC_TMUX_SETTINGS_CLEARANCE_BENCH=1 php artisan test --compact tests/Unit/RustCategorizerIrcTmuxSettingsClearanceParityTest.php --filter=test_benchmark_paired_workloads
     */
    public function test_benchmark_paired_workloads(): void
    {
        if (getenv('NNTMUX_CATEGORIZER_IRC_TMUX_SETTINGS_CLEARANCE_BENCH') !== '1') {
            self::markTestSkipped('Set NNTMUX_CATEGORIZER_IRC_TMUX_SETTINGS_CLEARANCE_BENCH=1 to run paired benchmarks.');
        }

        self::ffi();
        $categorizer = self::makeCategorizer(50);
        $ctxCommon = new ReleaseContext('Movie.Title.2026.1080p.WEB-DL.DDP5.1.H.264-GRP', 100, 'alt.binaries.movies.mkv', 'poster@example.com');
        $ctxUncommon = new ReleaseContext(str_repeat('Long.Release.Segment.', 20).'2160p.BluRay.x265-GRP', 200, 'alt.binaries.movies', 'poster@example.com');

        $catalog = new SettingsPageCatalog;
        $clearance = new ClearanceMiddleware;
        $allowUser = new class
        {
            public function hasDirectPermission(string $permission): bool
            {
                return true;
            }
        };
        $denyUser = new class
        {
            public function hasDirectPermission(string $permission): bool
            {
                return false;
            }
        };

        $workloads = [
            'categorizerGetPriority/common' => [
                static fn () => $categorizer->getPriorityPhp(),
                static fn () => $categorizer->getPriorityNative(),
            ],
            'categorizerGetPriority/uncommon' => [
                static fn () => $categorizer->getPriorityPhp(),
                static fn () => $categorizer->getPriorityNative(),
            ],
            'categorizerShouldSkip/common' => [
                static fn () => $categorizer->shouldSkipPhp($ctxCommon),
                static fn () => $categorizer->shouldSkipNative($ctxCommon),
            ],
            'categorizerShouldSkip/uncommon' => [
                static fn () => $categorizer->shouldSkipPhp($ctxUncommon),
                static fn () => $categorizer->shouldSkipNative($ctxUncommon),
            ],
            'categorizerMatched/common' => [
                static fn () => $categorizer->matchedPhp(Category::MOVIE_HD, 0.9, 'movie_hd')->matchedBy,
                static fn () => $categorizer->matchedNative(Category::MOVIE_HD, 0.9, 'movie_hd')->matchedBy,
            ],
            'categorizerMatched/uncommon' => [
                static fn () => $categorizer->matchedPhp(Category::TV_UHD, 0.85, 'tv_uhd_hevc_2160p_season_pack')->matchedBy,
                static fn () => $categorizer->matchedNative(Category::TV_UHD, 0.85, 'tv_uhd_hevc_2160p_season_pack')->matchedBy,
            ],
            'categorizerNoMatch/common' => [
                static fn () => $categorizer->noMatchPhp()->matchedBy,
                static fn () => $categorizer->noMatchNative()->matchedBy,
            ],
            'categorizerNoMatch/uncommon' => [
                static fn () => $categorizer->noMatchPhp()->categoryId,
                static fn () => $categorizer->noMatchNative()->categoryId,
            ],
            'ircNetworksEnabled/common' => [
                static fn () => IrcNetworks::enabledPhp(),
                static fn () => IrcNetworks::enabledNative(),
            ],
            'ircNetworksEnabled/uncommon' => [
                static fn () => IrcNetworks::enabledPhp(['synirc' => ['enabled' => true], 'corruptnet' => ['enabled' => true], 'zenet' => ['enabled' => true], 'efnet' => ['enabled' => false]]),
                static fn () => IrcNetworks::enabledNative(['synirc' => ['enabled' => true], 'corruptnet' => ['enabled' => true], 'zenet' => ['enabled' => true], 'efnet' => ['enabled' => false]]),
            ],
            'ircNetworksResolve/common' => [
                static fn () => IrcNetworks::resolvePhp('corruptnet'),
                static fn () => IrcNetworks::resolveNative('corruptnet'),
            ],
            'ircNetworksResolve/uncommon' => [
                static fn () => IrcNetworks::resolvePhp('synirc'),
                static fn () => IrcNetworks::resolveNative('synirc'),
            ],
            'ircNetworksLegacyChannels/common' => [
                static fn () => IrcNetworks::legacyChannelsPhp('a:1:{s:10:"#PreNNTmux";N;}'),
                static fn () => IrcNetworks::legacyChannelsNative('a:1:{s:10:"#PreNNTmux";N;}'),
            ],
            'ircNetworksLegacyChannels/uncommon' => [
                static fn () => IrcNetworks::legacyChannelsPhp('a:3:{s:10:"#PreNNTmux";N;s:8:"#private";s:6:"secret";s:4:"#pre";N;}'),
                static fn () => IrcNetworks::legacyChannelsNative('a:3:{s:10:"#PreNNTmux";N;s:8:"#private";s:6:"secret";s:4:"#pre";N;}'),
            ],
            'tmuxArguments/common' => [
                static fn () => TmuxCommand::argumentsPhp(['has-session', '-t', '=nntmux'], ''),
                static fn () => TmuxCommand::argumentsNative(['has-session', '-t', '=nntmux'], ''),
            ],
            'tmuxArguments/uncommon' => [
                static fn () => TmuxCommand::argumentsPhp(['list-panes', '-s', '-t', 'nntmux:0', '-F', "#{window_index}:#{pane_index}\t#{pane_title}"], 'nntmux-sock'),
                static fn () => TmuxCommand::argumentsNative(['list-panes', '-s', '-t', 'nntmux:0', '-F', "#{window_index}:#{pane_index}\t#{pane_title}"], 'nntmux-sock'),
            ],
            'tmuxMonitor/common' => [
                static fn () => TmuxCommand::monitorPhp('nntmux', '/usr/bin/php', '/var/www/nntmux/artisan'),
                static fn () => TmuxCommand::monitorNative('nntmux', '/usr/bin/php', '/var/www/nntmux/artisan'),
            ],
            'tmuxMonitor/uncommon' => [
                static fn () => TmuxCommand::monitorPhp('production-tmux-session-01', '/usr/local/bin/php', '/srv/nntmux/current/artisan'),
                static fn () => TmuxCommand::monitorNative('production-tmux-session-01', '/usr/local/bin/php', '/srv/nntmux/current/artisan'),
            ],
            'settingsWritableColumns/common' => [
                static fn () => $catalog->writableColumnsPhp(ConfigurationDomain::Site),
                static fn () => $catalog->writableColumnsNative(ConfigurationDomain::Site),
            ],
            'settingsWritableColumns/uncommon' => [
                static fn () => $catalog->writableColumnsPhp(ConfigurationDomain::Tmux),
                static fn () => $catalog->writableColumnsNative(ConfigurationDomain::Tmux),
            ],
            'settingsField/common' => [
                static fn () => $catalog->fieldPhp(ConfigurationDomain::Ingestion, 'completion_percent')->rules,
                static fn () => $catalog->fieldNative(ConfigurationDomain::Ingestion, 'completion_percent')->rules,
            ],
            'settingsField/uncommon' => [
                static fn () => $catalog->fieldPhp(ConfigurationDomain::Tmux, 'cleanup_rules')->options,
                static fn () => $catalog->fieldNative(ConfigurationDomain::Tmux, 'cleanup_rules')->options,
            ],
            'settingsOptions/common' => [
                static fn () => $catalog->optionsPhp('movie_lookup'),
                static fn () => $catalog->optionsNative('movie_lookup'),
            ],
            'settingsOptions/uncommon' => [
                static fn () => $catalog->optionsPhp('backfill_order'),
                static fn () => $catalog->optionsNative('backfill_order'),
            ],
            'settingsIsInteger/common' => [
                static fn () => $catalog->isIntegerPhp(ConfigurationDomain::Ingestion, 'binary_threads'),
                static fn () => $catalog->isIntegerNative(ConfigurationDomain::Ingestion, 'binary_threads'),
            ],
            'settingsIsInteger/uncommon' => [
                static fn () => $catalog->isIntegerPhp(ConfigurationDomain::Tmux, 'post_mode'),
                static fn () => $catalog->isIntegerNative(ConfigurationDomain::Tmux, 'post_mode'),
            ],
            'settingsHelp/common' => [
                static fn () => $catalog->helpPhp('home_link'),
                static fn () => $catalog->helpNative('home_link'),
            ],
            'settingsHelp/uncommon' => [
                static fn () => $catalog->helpPhp('misc_hashed_retention_hours'),
                static fn () => $catalog->helpNative('misc_hashed_retention_hours'),
            ],
            'settingsUnit/common' => [
                static fn () => $catalog->unitPhp('timeout_seconds'),
                static fn () => $catalog->unitNative('timeout_seconds'),
            ],
            'settingsUnit/uncommon' => [
                static fn () => $catalog->unitPhp('amazon_sleep_milliseconds'),
                static fn () => $catalog->unitNative('amazon_sleep_milliseconds'),
            ],
            'settingsCleanupRules/common' => [
                static fn () => SettingsPageCatalog::cleanupRulesPhp(),
                static fn () => SettingsPageCatalog::cleanupRulesNative(),
            ],
            'settingsCleanupRules/uncommon' => [
                static fn () => SettingsPageCatalog::cleanupRulesPhp(),
                static fn () => SettingsPageCatalog::cleanupRulesNative(),
            ],
            'clearanceMatchesPath/common' => [
                static fn () => $clearance->matchesCategoryPathPhp('Movies/HD', 'Movies'),
                static fn () => $clearance->matchesCategoryPathNative('Movies/HD', 'Movies'),
            ],
            'clearanceMatchesPath/uncommon' => [
                static fn () => $clearance->matchesCategoryPathPhp('browse/trending-movies/2026', 'trending-movies'),
                static fn () => $clearance->matchesCategoryPathNative('browse/trending-movies/2026', 'trending-movies'),
            ],
            'clearanceMainCategory/common' => [
                static fn () => $clearance->checkMainCategoryPermissionPhp($allowUser, 'Movies'),
                static fn () => $clearance->checkMainCategoryPermissionNative($allowUser, 'Movies'),
            ],
            'clearanceMainCategory/uncommon' => [
                static fn () => $clearance->checkMainCategoryPermissionPhp($denyUser, 'XXX'),
                static fn () => $clearance->checkMainCategoryPermissionNative($denyUser, 'XXX'),
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
                $phpP99 = $percentile99($samples[0]);
                $rustP99 = $percentile99($samples[1]);
                $reps[] = sprintf('rep%d=%.2fx/%.2fx', $rep + 1, $phpMean / $rustMean, $phpP99 / $rustP99);
            }
            fwrite(STDOUT, sprintf("%-32s %s\n", $name, implode('  ', $reps)));
        }
    }
}
