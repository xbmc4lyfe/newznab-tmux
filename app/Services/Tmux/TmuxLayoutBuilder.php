<?php

declare(strict_types=1);

namespace App\Services\Tmux;

use App\Enums\TmuxMode;
use App\Enums\TmuxPaneRole;
use App\Services\Configuration\ConfigurationProvider;
use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
use Illuminate\Support\Facades\Process;
use RuntimeException;
use Throwable;

/**
 * Service for building tmux window layouts based on sequential mode
 *
 * Uses Nerd Font icons for pane names to provide a modern visual experience.
 */
class TmuxLayoutBuilder
{
    public const string FFI_CDEF = <<<'C'
        ssize_t nntmux_tmux_layout_pane_display_name(const char *name_ptr, size_t name_len, char *out_buf, size_t out_cap);
        ssize_t nntmux_tmux_layout_plan_core_steps_nul(int32_t sequential_mode, int64_t *out_step_count, char *out_buf, size_t out_cap);
        ssize_t nntmux_tmux_layout_format_tool_command(const char *tool_ptr, size_t tool_len, const char *args_ptr, size_t args_len, char *out_buf, size_t out_cap);
        ssize_t nntmux_tmux_layout_plan_redis_command_nul(const char *redis_args_ptr, size_t redis_args_len, uint8_t has_redis_cli, const char *redis_host_ptr, size_t redis_host_len, const char *redis_port_ptr, size_t redis_port_len, int64_t refresh_interval, uint8_t use_sail, uint8_t override_host, const char *resolved_host_ptr, size_t resolved_host_len, const char *php_binary_ptr, size_t php_binary_len, const char *artisan_ptr, size_t artisan_len, const char *sail_ptr, size_t sail_len, int32_t *out_mode, char *out_buf, size_t out_cap);
        ssize_t nntmux_tmux_layout_format_operation_error(const char *operation_ptr, size_t operation_len, const char *error_ptr, size_t error_len, char *out_buf, size_t out_cap);
        C;

    /**
     * Default Nerd Font pane icons mapping.
     *
     * @var array<string, string>
     */
    public const array DEFAULT_PANE_ICONS = [
        // Core processing
        'Monitor' => '󰍹 Monitor',
        'update_binaries' => ' Binaries',
        'backfill' => '󰑓 Backfill',
        'update_releases' => ' Releases',
        'sequential' => '󰒿 Sequential',

        // Utilities
        'fixReleaseNames' => '󰯃 Fix Names',
        'removeCrapReleases' => '󰆴 Remove Crap',

        // Postprocessing
        'postprocessing_additional' => ' Additional',
        'postprocessing_tv' => '󰟴 TV/Anime',
        'postprocessing_amazon' => ' Metadata',
        'postprocessing_movies' => ' Movies',

        // IRC
        'scrapeIRC' => '󰻞 IRC Scraper',

        // Monitoring tools
        'htop' => ' htop',
        'nmon' => '󰨇 nmon',
        'vnstat' => '󰛳 vnstat',
        'tcptrack' => '󱘖 tcptrack',
        'bwm-ng' => '󰾆 bwm-ng',
        'mytop' => ' mytop',
        'redis' => ' Redis',
        'bash' => ' Console',
    ];

    private static bool $useRustFfi = false;

    private static FFI|false|null $ffi = null;

    private static ?CData $int64Slot = null;

    private static ?CData $int32Slot = null;

    private static ?FFI $slotOwner = null;

    protected TmuxSessionManager $sessionManager;

    protected TmuxPaneManager $paneManager;

    protected string $sessionName;

    private ?string $lastError = null;

    private bool $sessionCreated = false;

    /**
     * Pane name icons mapping - uses Nerd Font symbols
     * Requires a Nerd Font to display properly (FiraCode NF, JetBrains Mono NF, etc.)
     *
     * @var array<string, mixed>
     */
    protected array $paneIcons = self::DEFAULT_PANE_ICONS;

    public function __construct(TmuxSessionManager $sessionManager)
    {
        $this->sessionManager = $sessionManager;
        $this->sessionName = $sessionManager->sessionName();
        $this->paneManager = new TmuxPaneManager($this->sessionName);
    }

    public static function enableRustFfi(): void
    {
        self::setUseRustFfi(true);
    }

    public static function disableRustFfi(): void
    {
        self::setUseRustFfi(false);
    }

    public static function resetRustFfi(): void
    {
        self::setUseRustFfi(null);
    }

    public static function setUseRustFfi(?bool $use = true): void
    {
        self::$useRustFfi = $use ?? false;
        if ($use === null) {
            self::$ffi = null;
        }
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi;
    }

    public static function setFfiForTesting(FFI|false|null $override = null): void
    {
        self::$ffi = $override;
    }

    public static function mediaFfi(bool $reset = false, FFI|false|null $override = null): ?FFI
    {
        if ($reset) {
            self::$ffi = $override;
            if ($override !== null) {
                return self::$ffi === false ? null : self::$ffi;
            }
        }
        if (self::$ffi === null) {
            self::$ffi = RustPipelineLibrary::resolveDomain('media', self::FFI_CDEF) ?? false;
        }

        return self::$ffi === false ? null : self::$ffi;
    }

    /**
     * Get the display name with icon for a pane
     */
    protected function getPaneDisplayName(string $name): string
    {
        if ($this->paneIcons === self::DEFAULT_PANE_ICONS) {
            return self::resolvePaneDisplayName($name);
        }

        return (string) ($this->paneIcons[$name] ?? $name);
    }

    public static function resolvePaneDisplayName(string $name): string
    {
        return self::$useRustFfi
            ? self::resolvePaneDisplayNameNative($name)
            : self::resolvePaneDisplayNamePhp($name);
    }

    public static function resolvePaneDisplayNamePhp(string $name): string
    {
        return self::DEFAULT_PANE_ICONS[$name] ?? $name;
    }

    public static function resolvePaneDisplayNameNative(string $name): string
    {
        $ffi = self::mediaFfi();
        if ($ffi === null) {
            return self::resolvePaneDisplayNamePhp($name);
        }

        $nameLen = \strlen($name);
        $cap = 0;
        $scratch = RustPipelineLibrary::obtainScratch($ffi, max(64, $nameLen + 32), $cap);
        if ($scratch === null) {
            return self::resolvePaneDisplayNamePhp($name);
        }

        try {
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_tmux_layout_pane_display_name(
                $name,
                $nameLen,
                $scratch,
                $cap,
            );
            if (\is_int($written) && $written < -1) {
                $scratch = RustPipelineLibrary::obtainScratch($ffi, -$written, $cap);
                if ($scratch === null) {
                    return self::resolvePaneDisplayNamePhp($name);
                }
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_tmux_layout_pane_display_name(
                    $name,
                    $nameLen,
                    $scratch,
                    $cap,
                );
            }
            if (\is_int($written) && $written >= 0 && $written <= $cap) {
                return $written === 0 ? '' : FFI::string($scratch, $written);
            }
        } catch (Throwable) {
            // Fall through to PHP fallback.
        }

        return self::resolvePaneDisplayNamePhp($name);
    }

    /**
     * Plan the core window/pane creation and split steps for a sequential mode (`0` = Full, `1` = Basic, `2` = Stripped).
     *
     * @return list<array{op: string, window: int, window_name: string, target_role: string, percentage: int, role: string, pane_key: string, title: string}>
     */
    public static function planCoreLayoutSteps(int $sequentialMode): array
    {
        return self::$useRustFfi
            ? self::planCoreLayoutStepsNative($sequentialMode)
            : self::planCoreLayoutStepsPhp($sequentialMode);
    }

    /**
     * @return list<array{op: string, window: int, window_name: string, target_role: string, percentage: int, role: string, pane_key: string, title: string}>
     */
    public static function planCoreLayoutStepsPhp(int $sequentialMode): array
    {
        return match (TmuxMode::tryFrom($sequentialMode) ?? TmuxMode::Full) {
            TmuxMode::Basic => [
                ['op' => 'session', 'window' => 0, 'window_name' => '', 'target_role' => '', 'percentage' => 0, 'role' => TmuxPaneRole::Monitor->value, 'pane_key' => 'Monitor', 'title' => self::DEFAULT_PANE_ICONS['Monitor']],
                ['op' => 'split_h', 'window' => 0, 'window_name' => '', 'target_role' => TmuxPaneRole::Monitor->value, 'percentage' => 67, 'role' => TmuxPaneRole::Releases->value, 'pane_key' => 'update_releases', 'title' => self::DEFAULT_PANE_ICONS['update_releases']],
                ['op' => 'window', 'window' => 1, 'window_name' => ' Utils', 'target_role' => '', 'percentage' => 0, 'role' => TmuxPaneRole::FixNames->value, 'pane_key' => 'fixReleaseNames', 'title' => self::DEFAULT_PANE_ICONS['fixReleaseNames']],
                ['op' => 'split_h', 'window' => 1, 'window_name' => '', 'target_role' => TmuxPaneRole::FixNames->value, 'percentage' => 50, 'role' => TmuxPaneRole::RemoveCrap->value, 'pane_key' => 'removeCrapReleases', 'title' => self::DEFAULT_PANE_ICONS['removeCrapReleases']],
                ['op' => 'window', 'window' => 2, 'window_name' => ' Post', 'target_role' => '', 'percentage' => 0, 'role' => TmuxPaneRole::PostAdditional->value, 'pane_key' => 'postprocessing_additional', 'title' => self::DEFAULT_PANE_ICONS['postprocessing_additional']],
                ['op' => 'split_h', 'window' => 2, 'window_name' => '', 'target_role' => TmuxPaneRole::PostAdditional->value, 'percentage' => 50, 'role' => TmuxPaneRole::PostMovies->value, 'pane_key' => 'postprocessing_movies', 'title' => self::DEFAULT_PANE_ICONS['postprocessing_movies']],
                ['op' => 'split_v', 'window' => 2, 'window_name' => '', 'target_role' => TmuxPaneRole::PostAdditional->value, 'percentage' => 67, 'role' => TmuxPaneRole::PostTv->value, 'pane_key' => 'postprocessing_tv', 'title' => self::DEFAULT_PANE_ICONS['postprocessing_tv']],
                ['op' => 'split_v', 'window' => 2, 'window_name' => '', 'target_role' => TmuxPaneRole::PostTv->value, 'percentage' => 50, 'role' => TmuxPaneRole::PostMetadata->value, 'pane_key' => 'postprocessing_amazon', 'title' => self::DEFAULT_PANE_ICONS['postprocessing_amazon']],
                ['op' => 'window', 'window' => 3, 'window_name' => '󰻞 IRC', 'target_role' => '', 'percentage' => 0, 'role' => TmuxPaneRole::IrcScraper->value, 'pane_key' => 'scrapeIRC', 'title' => self::DEFAULT_PANE_ICONS['scrapeIRC']],
            ],
            TmuxMode::Stripped => [
                ['op' => 'session', 'window' => 0, 'window_name' => '', 'target_role' => '', 'percentage' => 0, 'role' => TmuxPaneRole::Monitor->value, 'pane_key' => 'Monitor', 'title' => self::DEFAULT_PANE_ICONS['Monitor']],
                ['op' => 'split_h', 'window' => 0, 'window_name' => '', 'target_role' => TmuxPaneRole::Monitor->value, 'percentage' => 67, 'role' => TmuxPaneRole::Sequential->value, 'pane_key' => 'sequential', 'title' => self::DEFAULT_PANE_ICONS['sequential']],
                ['op' => 'window', 'window' => 1, 'window_name' => ' Utils', 'target_role' => '', 'percentage' => 0, 'role' => TmuxPaneRole::FixNames->value, 'pane_key' => 'fixReleaseNames', 'title' => self::DEFAULT_PANE_ICONS['fixReleaseNames']],
                ['op' => 'split_h', 'window' => 1, 'window_name' => '', 'target_role' => TmuxPaneRole::FixNames->value, 'percentage' => 50, 'role' => TmuxPaneRole::PostMetadata->value, 'pane_key' => 'postprocessing_amazon', 'title' => self::DEFAULT_PANE_ICONS['postprocessing_amazon']],
                ['op' => 'window', 'window' => 3, 'window_name' => '󰻞 IRC', 'target_role' => '', 'percentage' => 0, 'role' => TmuxPaneRole::IrcScraper->value, 'pane_key' => 'scrapeIRC', 'title' => self::DEFAULT_PANE_ICONS['scrapeIRC']],
            ],
            default => [
                ['op' => 'session', 'window' => 0, 'window_name' => '', 'target_role' => '', 'percentage' => 0, 'role' => TmuxPaneRole::Monitor->value, 'pane_key' => 'Monitor', 'title' => self::DEFAULT_PANE_ICONS['Monitor']],
                ['op' => 'split_h', 'window' => 0, 'window_name' => '', 'target_role' => TmuxPaneRole::Monitor->value, 'percentage' => 67, 'role' => TmuxPaneRole::Binaries->value, 'pane_key' => 'update_binaries', 'title' => self::DEFAULT_PANE_ICONS['update_binaries']],
                ['op' => 'split_v', 'window' => 0, 'window_name' => '', 'target_role' => TmuxPaneRole::Binaries->value, 'percentage' => 67, 'role' => TmuxPaneRole::Backfill->value, 'pane_key' => 'backfill', 'title' => self::DEFAULT_PANE_ICONS['backfill']],
                ['op' => 'split_v', 'window' => 0, 'window_name' => '', 'target_role' => TmuxPaneRole::Backfill->value, 'percentage' => 50, 'role' => TmuxPaneRole::Releases->value, 'pane_key' => 'update_releases', 'title' => self::DEFAULT_PANE_ICONS['update_releases']],
                ['op' => 'window', 'window' => 1, 'window_name' => ' Utils', 'target_role' => '', 'percentage' => 0, 'role' => TmuxPaneRole::FixNames->value, 'pane_key' => 'fixReleaseNames', 'title' => self::DEFAULT_PANE_ICONS['fixReleaseNames']],
                ['op' => 'split_h', 'window' => 1, 'window_name' => '', 'target_role' => TmuxPaneRole::FixNames->value, 'percentage' => 50, 'role' => TmuxPaneRole::RemoveCrap->value, 'pane_key' => 'removeCrapReleases', 'title' => self::DEFAULT_PANE_ICONS['removeCrapReleases']],
                ['op' => 'window', 'window' => 2, 'window_name' => ' Post', 'target_role' => '', 'percentage' => 0, 'role' => TmuxPaneRole::PostAdditional->value, 'pane_key' => 'postprocessing_additional', 'title' => self::DEFAULT_PANE_ICONS['postprocessing_additional']],
                ['op' => 'split_h', 'window' => 2, 'window_name' => '', 'target_role' => TmuxPaneRole::PostAdditional->value, 'percentage' => 50, 'role' => TmuxPaneRole::PostMovies->value, 'pane_key' => 'postprocessing_movies', 'title' => self::DEFAULT_PANE_ICONS['postprocessing_movies']],
                ['op' => 'split_v', 'window' => 2, 'window_name' => '', 'target_role' => TmuxPaneRole::PostAdditional->value, 'percentage' => 67, 'role' => TmuxPaneRole::PostTv->value, 'pane_key' => 'postprocessing_tv', 'title' => self::DEFAULT_PANE_ICONS['postprocessing_tv']],
                ['op' => 'split_v', 'window' => 2, 'window_name' => '', 'target_role' => TmuxPaneRole::PostTv->value, 'percentage' => 50, 'role' => TmuxPaneRole::PostMetadata->value, 'pane_key' => 'postprocessing_amazon', 'title' => self::DEFAULT_PANE_ICONS['postprocessing_amazon']],
                ['op' => 'window', 'window' => 3, 'window_name' => '󰻞 IRC', 'target_role' => '', 'percentage' => 0, 'role' => TmuxPaneRole::IrcScraper->value, 'pane_key' => 'scrapeIRC', 'title' => self::DEFAULT_PANE_ICONS['scrapeIRC']],
            ],
        };
    }

    /**
     * @return list<array{op: string, window: int, window_name: string, target_role: string, percentage: int, role: string, pane_key: string, title: string}>
     */
    public static function planCoreLayoutStepsNative(int $sequentialMode): array
    {
        $ffi = self::mediaFfi();
        if ($ffi === null || ! self::ensureSlots($ffi) || self::$int64Slot === null) {
            return self::planCoreLayoutStepsPhp($sequentialMode);
        }

        $cap = 0;
        $scratch = RustPipelineLibrary::obtainScratch($ffi, 1024, $cap);
        if ($scratch === null) {
            return self::planCoreLayoutStepsPhp($sequentialMode);
        }

        try {
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_tmux_layout_plan_core_steps_nul(
                $sequentialMode,
                self::$int64Slot,
                $scratch,
                $cap,
            );
            if (\is_int($written) && $written < -1) {
                $scratch = RustPipelineLibrary::obtainScratch($ffi, -$written, $cap);
                if ($scratch === null) {
                    return self::planCoreLayoutStepsPhp($sequentialMode);
                }
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_tmux_layout_plan_core_steps_nul(
                    $sequentialMode,
                    self::$int64Slot,
                    $scratch,
                    $cap,
                );
            }
            if (! \is_int($written) || $written <= 0 || $written > $cap) {
                return self::planCoreLayoutStepsPhp($sequentialMode);
            }

            /** @var int $stepCount */
            $stepCount = self::$int64Slot[0];
            $parts = explode("\0", FFI::string($scratch, $written));
            if ($stepCount <= 0 || \count($parts) !== $stepCount * 8) {
                return self::planCoreLayoutStepsPhp($sequentialMode);
            }

            $steps = [];
            for ($i = 0; $i < $stepCount; $i++) {
                $base = $i * 8;
                $steps[] = [
                    'op' => $parts[$base],
                    'window' => (int) $parts[$base + 1],
                    'window_name' => $parts[$base + 2],
                    'target_role' => $parts[$base + 3],
                    'percentage' => (int) $parts[$base + 4],
                    'role' => $parts[$base + 5],
                    'pane_key' => $parts[$base + 6],
                    'title' => $parts[$base + 7],
                ];
            }

            return $steps;
        } catch (Throwable) {
            return self::planCoreLayoutStepsPhp($sequentialMode);
        }
    }

    public static function formatToolCommand(string $tool, string $args = ''): ?string
    {
        return self::$useRustFfi
            ? self::formatToolCommandNative($tool, $args)
            : self::formatToolCommandPhp($tool, $args);
    }

    public static function formatToolCommandPhp(string $tool, string $args = ''): ?string
    {
        return match ($tool) {
            'htop' => 'htop',
            'nmon' => 'nmon -t',
            'vnstat' => "watch -n10 'vnstat {$args}'",
            'tcptrack' => "tcptrack {$args}",
            'bwm-ng' => 'bwm-ng',
            'mytop' => 'mytop -u',
            'bash' => 'bash -i',
            default => null,
        };
    }

    public static function formatToolCommandNative(string $tool, string $args = ''): ?string
    {
        $ffi = self::mediaFfi();
        if ($ffi === null) {
            return self::formatToolCommandPhp($tool, $args);
        }

        $toolLen = \strlen($tool);
        $argsLen = \strlen($args);
        $cap = 0;
        $scratch = RustPipelineLibrary::obtainScratch($ffi, max(128, $toolLen + $argsLen + 32), $cap);
        if ($scratch === null) {
            return self::formatToolCommandPhp($tool, $args);
        }

        try {
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_tmux_layout_format_tool_command(
                $tool,
                $toolLen,
                $args,
                $argsLen,
                $scratch,
                $cap,
            );
            if (\is_int($written) && $written === -2) {
                return null;
            }
            if (\is_int($written) && $written < -2) {
                $scratch = RustPipelineLibrary::obtainScratch($ffi, -$written, $cap);
                if ($scratch === null) {
                    return self::formatToolCommandPhp($tool, $args);
                }
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_tmux_layout_format_tool_command(
                    $tool,
                    $toolLen,
                    $args,
                    $argsLen,
                    $scratch,
                    $cap,
                );
            }
            if (\is_int($written) && $written > 0 && $written <= $cap) {
                return FFI::string($scratch, $written);
            }
        } catch (Throwable) {
            // Fall through to PHP fallback.
        }

        return self::formatToolCommandPhp($tool, $args);
    }

    /**
     * Plan the Redis monitor pane command and environment array.
     *
     * @return array{command: string|list<string>, environment: array<string, string>}
     */
    public static function planRedisPaneCommand(
        string $redisArgs,
        bool $hasRedisCli,
        string $redisHost,
        int|string $redisPort,
        int $refreshInterval,
        bool $useSail,
        bool $overrideHost,
        string $resolvedHost,
        string $phpBinary,
        string $artisanPath,
        string $sailPath,
    ): array {
        return self::$useRustFfi
            ? self::planRedisPaneCommandNative(
                $redisArgs,
                $hasRedisCli,
                $redisHost,
                $redisPort,
                $refreshInterval,
                $useSail,
                $overrideHost,
                $resolvedHost,
                $phpBinary,
                $artisanPath,
                $sailPath,
            )
            : self::planRedisPaneCommandPhp(
                $redisArgs,
                $hasRedisCli,
                $redisHost,
                $redisPort,
                $refreshInterval,
                $useSail,
                $overrideHost,
                $resolvedHost,
                $phpBinary,
                $artisanPath,
                $sailPath,
            );
    }

    /**
     * @return array{command: string|list<string>, environment: array<string, string>}
     */
    public static function planRedisPaneCommandPhp(
        string $redisArgs,
        bool $hasRedisCli,
        string $redisHost,
        int|string $redisPort,
        int $refreshInterval,
        bool $useSail,
        bool $overrideHost,
        string $resolvedHost,
        string $phpBinary,
        string $artisanPath,
        string $sailPath,
    ): array {
        if (! empty($redisArgs) && $redisArgs !== 'NULL' && $hasRedisCli) {
            return [
                'command' => "watch -n{$refreshInterval} -c 'redis-cli -h {$redisHost} -p {$redisPort} {$redisArgs}'",
                'environment' => [],
            ];
        }

        if ($useSail) {
            return [
                'command' => "{$sailPath} artisan redis:monitor --refresh={$refreshInterval}",
                'environment' => [],
            ];
        }

        return [
            'command' => [$phpBinary, $artisanPath, 'redis:monitor', '--refresh='.$refreshInterval],
            'environment' => $overrideHost ? ['REDIS_HOST' => $resolvedHost] : [],
        ];
    }

    /**
     * @return array{command: string|list<string>, environment: array<string, string>}
     */
    public static function planRedisPaneCommandNative(
        string $redisArgs,
        bool $hasRedisCli,
        string $redisHost,
        int|string $redisPort,
        int $refreshInterval,
        bool $useSail,
        bool $overrideHost,
        string $resolvedHost,
        string $phpBinary,
        string $artisanPath,
        string $sailPath,
    ): array {
        $ffi = self::mediaFfi();
        $portStr = (string) $redisPort;
        if (
            $ffi === null
            || ! self::ensureSlots($ffi)
            || self::$int32Slot === null
            || str_contains($redisArgs, "\0")
            || str_contains($redisHost, "\0")
            || str_contains($portStr, "\0")
            || str_contains($resolvedHost, "\0")
            || str_contains($phpBinary, "\0")
            || str_contains($artisanPath, "\0")
            || str_contains($sailPath, "\0")
        ) {
            return self::planRedisPaneCommandPhp(
                $redisArgs,
                $hasRedisCli,
                $redisHost,
                $redisPort,
                $refreshInterval,
                $useSail,
                $overrideHost,
                $resolvedHost,
                $phpBinary,
                $artisanPath,
                $sailPath,
            );
        }

        $cap = 0;
        $scratch = RustPipelineLibrary::obtainScratch(
            $ffi,
            max(256, \strlen($redisArgs) + \strlen($redisHost) + \strlen($phpBinary) + \strlen($artisanPath) + \strlen($sailPath) + 96),
            $cap
        );
        if ($scratch === null) {
            return self::planRedisPaneCommandPhp(
                $redisArgs,
                $hasRedisCli,
                $redisHost,
                $redisPort,
                $refreshInterval,
                $useSail,
                $overrideHost,
                $resolvedHost,
                $phpBinary,
                $artisanPath,
                $sailPath,
            );
        }

        try {
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_tmux_layout_plan_redis_command_nul(
                $redisArgs,
                \strlen($redisArgs),
                $hasRedisCli ? 1 : 0,
                $redisHost,
                \strlen($redisHost),
                $portStr,
                \strlen($portStr),
                $refreshInterval,
                $useSail ? 1 : 0,
                $overrideHost ? 1 : 0,
                $resolvedHost,
                \strlen($resolvedHost),
                $phpBinary,
                \strlen($phpBinary),
                $artisanPath,
                \strlen($artisanPath),
                $sailPath,
                \strlen($sailPath),
                self::$int32Slot,
                $scratch,
                $cap,
            );
            if (\is_int($written) && $written < -1) {
                $scratch = RustPipelineLibrary::obtainScratch($ffi, -$written, $cap);
                if ($scratch === null) {
                    return self::planRedisPaneCommandPhp(
                        $redisArgs,
                        $hasRedisCli,
                        $redisHost,
                        $redisPort,
                        $refreshInterval,
                        $useSail,
                        $overrideHost,
                        $resolvedHost,
                        $phpBinary,
                        $artisanPath,
                        $sailPath,
                    );
                }
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_tmux_layout_plan_redis_command_nul(
                    $redisArgs,
                    \strlen($redisArgs),
                    $hasRedisCli ? 1 : 0,
                    $redisHost,
                    \strlen($redisHost),
                    $portStr,
                    \strlen($portStr),
                    $refreshInterval,
                    $useSail ? 1 : 0,
                    $overrideHost ? 1 : 0,
                    $resolvedHost,
                    \strlen($resolvedHost),
                    $phpBinary,
                    \strlen($phpBinary),
                    $artisanPath,
                    \strlen($artisanPath),
                    $sailPath,
                    \strlen($sailPath),
                    self::$int32Slot,
                    $scratch,
                    $cap,
                );
            }
            if (! \is_int($written) || $written <= 0 || $written > $cap) {
                return self::planRedisPaneCommandPhp(
                    $redisArgs,
                    $hasRedisCli,
                    $redisHost,
                    $redisPort,
                    $refreshInterval,
                    $useSail,
                    $overrideHost,
                    $resolvedHost,
                    $phpBinary,
                    $artisanPath,
                    $sailPath,
                );
            }

            /** @var int $mode */
            $mode = self::$int32Slot[0];
            $payload = FFI::string($scratch, $written);

            if ($mode === 0 || $mode === 1) {
                return [
                    'command' => $payload,
                    'environment' => [],
                ];
            }

            $parts = explode("\0", $payload);
            if ($mode === 2 && \count($parts) === 4) {
                return [
                    'command' => [$parts[0], $parts[1], $parts[2], $parts[3]],
                    'environment' => [],
                ];
            }
            if ($mode === 3 && \count($parts) === 5) {
                return [
                    'command' => [$parts[0], $parts[1], $parts[2], $parts[3]],
                    'environment' => ['REDIS_HOST' => $parts[4]],
                ];
            }
        } catch (Throwable) {
            // Fall through to PHP fallback.
        }

        return self::planRedisPaneCommandPhp(
            $redisArgs,
            $hasRedisCli,
            $redisHost,
            $redisPort,
            $refreshInterval,
            $useSail,
            $overrideHost,
            $resolvedHost,
            $phpBinary,
            $artisanPath,
            $sailPath,
        );
    }

    public static function formatOperationError(string $operation, ?string $error = null): string
    {
        return self::$useRustFfi
            ? self::formatOperationErrorNative($operation, $error)
            : self::formatOperationErrorPhp($operation, $error);
    }

    public static function formatOperationErrorPhp(string $operation, ?string $error = null): string
    {
        $suffix = $error === null || $error === '' ? '' : ": {$error}";

        return "Unable to {$operation}{$suffix}.";
    }

    public static function formatOperationErrorNative(string $operation, ?string $error = null): string
    {
        $ffi = self::mediaFfi();
        if ($ffi === null) {
            return self::formatOperationErrorPhp($operation, $error);
        }

        $errStr = $error ?? '';
        $opLen = \strlen($operation);
        $errLen = \strlen($errStr);
        $cap = 0;
        $scratch = RustPipelineLibrary::obtainScratch($ffi, max(128, $opLen + $errLen + 32), $cap);
        if ($scratch === null) {
            return self::formatOperationErrorPhp($operation, $error);
        }

        try {
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_tmux_layout_format_operation_error(
                $operation,
                $opLen,
                $errStr,
                $errLen,
                $scratch,
                $cap,
            );
            if (\is_int($written) && $written < -1) {
                $scratch = RustPipelineLibrary::obtainScratch($ffi, -$written, $cap);
                if ($scratch === null) {
                    return self::formatOperationErrorPhp($operation, $error);
                }
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_tmux_layout_format_operation_error(
                    $operation,
                    $opLen,
                    $errStr,
                    $errLen,
                    $scratch,
                    $cap,
                );
            }
            if (\is_int($written) && $written > 0 && $written <= $cap) {
                return FFI::string($scratch, $written);
            }
        } catch (Throwable) {
            // Fall through to PHP fallback.
        }

        return self::formatOperationErrorPhp($operation, $error);
    }

    /**
     * Build the appropriate layout based on sequential mode
     */
    public function buildLayout(int $sequentialMode): bool
    {
        try {
            $this->expectedRoles = array_values((TmuxMode::tryFrom($sequentialMode) ?? TmuxMode::Full)->tasks());
            match (TmuxMode::tryFrom($sequentialMode) ?? TmuxMode::Full) {
                TmuxMode::Basic => $this->buildBasicLayout(),
                TmuxMode::Stripped => $this->buildStrippedLayout(),
                default => $this->buildFullLayout(),
            };

            foreach ($this->expectedRoles as $role) {
                if (! in_array($role, $this->configuredRoles, true)) {
                    throw new RuntimeException("Scheduled role '{$role->value}' has no pane in this layout.");
                }
            }

            return true;
        } catch (Throwable $exception) {
            $this->lastError = $exception->getMessage();

            if ($this->sessionCreated) {
                $this->sessionManager->killSession();
            }

            return false;
        }
    }

    /**
     * Build full non-sequential layout (mode 0)
     */
    protected function buildFullLayout(): void
    {
        // Window 0: Monitor + Binaries + Backfill + Releases
        $monitor = $this->createSessionPane(TmuxPaneRole::Monitor, $this->getPaneDisplayName('Monitor'));
        $binaries = $this->splitHorizontal(
            $monitor,
            67,
            TmuxPaneRole::Binaries,
            $this->getPaneDisplayName('update_binaries'),
        );
        $backfill = $this->splitVertical(
            $binaries,
            67,
            TmuxPaneRole::Backfill,
            $this->getPaneDisplayName('backfill'),
        );
        $this->splitVertical(
            $backfill,
            50,
            TmuxPaneRole::Releases,
            $this->getPaneDisplayName('update_releases'),
        );

        // Window 1: Fix names + Remove crap
        $fixNames = $this->createWindowPane(
            1,
            ' Utils',
            TmuxPaneRole::FixNames,
            $this->getPaneDisplayName('fixReleaseNames'),
        );
        $this->splitHorizontal(
            $fixNames,
            50,
            TmuxPaneRole::RemoveCrap,
            $this->getPaneDisplayName('removeCrapReleases'),
        );

        // Window 2: Postprocessing (left stack and right-side movies)
        $additional = $this->createWindowPane(
            2,
            ' Post',
            TmuxPaneRole::PostAdditional,
            $this->getPaneDisplayName('postprocessing_additional'),
        );
        $this->splitHorizontal(
            $additional,
            50,
            TmuxPaneRole::PostMovies,
            $this->getPaneDisplayName('postprocessing_movies'),
        );
        $tv = $this->splitVertical(
            $additional,
            67,
            TmuxPaneRole::PostTv,
            $this->getPaneDisplayName('postprocessing_tv'),
        );
        $this->splitVertical(
            $tv,
            50,
            TmuxPaneRole::PostMetadata,
            $this->getPaneDisplayName('postprocessing_amazon'),
        );

        // Window 3: IRC Scraper
        $this->createIRCScraperWindow();

        // Additional windows (optional monitoring tools)
        $this->createOptionalWindows();

    }

    /**
     * Build basic sequential layout (mode 1)
     */
    protected function buildBasicLayout(): void
    {
        // Window 0: Monitor + Releases
        $monitor = $this->createSessionPane(TmuxPaneRole::Monitor, $this->getPaneDisplayName('Monitor'));
        $this->splitHorizontal(
            $monitor,
            67,
            TmuxPaneRole::Releases,
            $this->getPaneDisplayName('update_releases'),
        );

        // Window 1: Utils (Fix names + Remove crap)
        $fixNames = $this->createWindowPane(
            1,
            ' Utils',
            TmuxPaneRole::FixNames,
            $this->getPaneDisplayName('fixReleaseNames'),
        );
        $this->splitHorizontal(
            $fixNames,
            50,
            TmuxPaneRole::RemoveCrap,
            $this->getPaneDisplayName('removeCrapReleases'),
        );

        // Window 2: Postprocessing
        $additional = $this->createWindowPane(
            2,
            ' Post',
            TmuxPaneRole::PostAdditional,
            $this->getPaneDisplayName('postprocessing_additional'),
        );
        $this->splitHorizontal(
            $additional,
            50,
            TmuxPaneRole::PostMovies,
            $this->getPaneDisplayName('postprocessing_movies'),
        );
        $tv = $this->splitVertical(
            $additional,
            67,
            TmuxPaneRole::PostTv,
            $this->getPaneDisplayName('postprocessing_tv'),
        );
        $this->splitVertical(
            $tv,
            50,
            TmuxPaneRole::PostMetadata,
            $this->getPaneDisplayName('postprocessing_amazon'),
        );

        // Window 3: IRC Scraper
        $this->createIRCScraperWindow();

        $this->createOptionalWindows();

    }

    /**
     * Build stripped sequential layout (mode 2)
     */
    protected function buildStrippedLayout(): void
    {
        // Window 0: Monitor + Sequential
        $monitor = $this->createSessionPane(TmuxPaneRole::Monitor, $this->getPaneDisplayName('Monitor'));
        $this->splitHorizontal(
            $monitor,
            67,
            TmuxPaneRole::Sequential,
            $this->getPaneDisplayName('sequential'),
        );

        // Window 1: Metadata postprocessing
        $fixNames = $this->createWindowPane(
            1,
            ' Utils',
            TmuxPaneRole::FixNames,
            $this->getPaneDisplayName('fixReleaseNames'),
        );
        $this->splitHorizontal(
            $fixNames,
            50,
            TmuxPaneRole::PostMetadata,
            $this->getPaneDisplayName('postprocessing_amazon'),
        );

        // Window 2: IRC Scraper
        $this->createIRCScraperWindow();

        $this->createOptionalWindows();

    }

    /**
     * Create IRC scraper window
     */
    protected function createIRCScraperWindow(): void
    {
        $this->createWindowPane(
            3,
            '󰻞 IRC',
            TmuxPaneRole::IrcScraper,
            $this->getPaneDisplayName('scrapeIRC'),
        );
    }

    /**
     * Create optional monitoring windows based on settings
     *
     * Creates separate tmux windows for enabled monitoring tools.
     * Each tool gets its own dedicated window starting from index 4.
     *
     * Window layout:
     * - Window 0: Monitor + Processing panes (binaries/backfill/releases)
     * - Window 1: Utilities (fix names, remove crap)
     * - Window 2: Postprocessing (additional, tv/anime, movies, metadata)
     * - Window 3: IRC Scraper
     * - Window 4+: Monitoring tools (htop, nmon, vnstat, tcptrack, bwm-ng, mytop, redis, console)
     */
    protected function createOptionalWindows(): void
    {
        $windowIndex = 4;

        // htop
        if ((int) app(ConfigurationProvider::class)->tmux()->htopEnabled === 1 && $this->commandExists('htop')) {
            $pane = $this->createWindowPane($windowIndex, $this->getPaneDisplayName('htop'), TmuxPaneRole::Htop, $this->getPaneDisplayName('htop'));
            $this->requireSuccess($this->paneManager->respawnPane($pane, (string) self::formatToolCommand('htop')), 'respawn htop pane');
            $windowIndex++;
        }

        // nmon
        if ((int) app(ConfigurationProvider::class)->tmux()->nmonEnabled === 1 && $this->commandExists('nmon')) {
            $pane = $this->createWindowPane($windowIndex, $this->getPaneDisplayName('nmon'), TmuxPaneRole::Nmon, $this->getPaneDisplayName('nmon'));
            $this->requireSuccess($this->paneManager->respawnPane($pane, (string) self::formatToolCommand('nmon')), 'respawn nmon pane');
            $windowIndex++;
        }

        // vnstat
        if ((int) app(ConfigurationProvider::class)->tmux()->vnstatEnabled === 1 && $this->commandExists('vnstat')) {
            $vnstatArgs = app(ConfigurationProvider::class)->tmux()->vnstatArgs ?? '';
            $pane = $this->createWindowPane($windowIndex, $this->getPaneDisplayName('vnstat'), TmuxPaneRole::Vnstat, $this->getPaneDisplayName('vnstat'));
            $this->requireSuccess($this->paneManager->respawnPane($pane, (string) self::formatToolCommand('vnstat', (string) $vnstatArgs)), 'respawn vnstat pane');
            $windowIndex++;
        }

        // tcptrack
        if ((int) app(ConfigurationProvider::class)->tmux()->tcpTrackEnabled === 1 && $this->commandExists('tcptrack')) {
            $tcptrackArgs = app(ConfigurationProvider::class)->tmux()->tcpTrackArgs ?? '';
            $pane = $this->createWindowPane($windowIndex, $this->getPaneDisplayName('tcptrack'), TmuxPaneRole::Tcptrack, $this->getPaneDisplayName('tcptrack'));
            $this->requireSuccess($this->paneManager->respawnPane($pane, (string) self::formatToolCommand('tcptrack', (string) $tcptrackArgs)), 'respawn tcptrack pane');
            $windowIndex++;
        }

        // bwm-ng
        if ((int) app(ConfigurationProvider::class)->tmux()->bwmngEnabled === 1 && $this->commandExists('bwm-ng')) {
            $pane = $this->createWindowPane($windowIndex, $this->getPaneDisplayName('bwm-ng'), TmuxPaneRole::BandwidthMonitor, $this->getPaneDisplayName('bwm-ng'));
            $this->requireSuccess($this->paneManager->respawnPane($pane, (string) self::formatToolCommand('bwm-ng')), 'respawn bwm-ng pane');
            $windowIndex++;
        }

        // mytop
        if ((int) app(ConfigurationProvider::class)->tmux()->mytopEnabled === 1 && $this->commandExists('mytop')) {
            $pane = $this->createWindowPane($windowIndex, $this->getPaneDisplayName('mytop'), TmuxPaneRole::Mytop, $this->getPaneDisplayName('mytop'));
            $this->requireSuccess($this->paneManager->respawnPane($pane, (string) self::formatToolCommand('mytop')), 'respawn mytop pane');
            $windowIndex++;
        }

        // redis monitoring
        if ((int) app(ConfigurationProvider::class)->tmux()->redisEnabled === 1) {
            $redisArgs = (string) (app(ConfigurationProvider::class)->tmux()->redisArgs ?? '');
            $refreshInterval = 30;

            $pane = $this->createWindowPane($windowIndex, $this->getPaneDisplayName('redis'), TmuxPaneRole::Redis, $this->getPaneDisplayName('redis'));

            $redisHost = (string) config('database.redis.default.host', '127.0.0.1');
            $redisPort = config('database.redis.default.port', 6379);
            $hasRedisCli = ! empty($redisArgs) && $redisArgs !== 'NULL' && $this->commandExists('redis-cli');

            if ($hasRedisCli) {
                $plan = self::planRedisPaneCommand(
                    $redisArgs,
                    true,
                    $redisHost,
                    (string) $redisPort,
                    $refreshInterval,
                    false,
                    false,
                    $redisHost,
                    PHP_BINARY,
                    base_path('artisan'),
                    base_path('sail'),
                );
            } else {
                $connectionInfo = $this->resolveRedisConnection($redisHost, (int) $redisPort);
                $plan = self::planRedisPaneCommand(
                    $redisArgs,
                    false,
                    $redisHost,
                    (int) $redisPort,
                    $refreshInterval,
                    (bool) $connectionInfo['use_sail'],
                    (bool) $connectionInfo['override_host'],
                    (string) $connectionInfo['host'],
                    PHP_BINARY,
                    base_path('artisan'),
                    base_path('sail'),
                );
            }

            if ($plan['environment'] === []) {
                $this->requireSuccess($this->paneManager->respawnPane($pane, $plan['command']), 'respawn Redis pane');
            } else {
                $this->requireSuccess(
                    $this->paneManager->respawnPane($pane, $plan['command'], environment: $plan['environment']),
                    'respawn Redis pane',
                );
            }
            $windowIndex++;
        }

        // bash console
        if ((int) app(ConfigurationProvider::class)->tmux()->consoleEnabled === 1) {
            $pane = $this->createWindowPane($windowIndex, $this->getPaneDisplayName('bash'), TmuxPaneRole::Console, $this->getPaneDisplayName('bash'));
            $this->requireSuccess($this->paneManager->respawnPane($pane, (string) self::formatToolCommand('bash')), 'respawn console pane');
        }
    }

    public function lastError(): ?string
    {
        return $this->lastError;
    }

    private function createSessionPane(TmuxPaneRole $role, string $title): string
    {
        $paneId = $this->sessionManager->createSession($title);
        if ($paneId === null) {
            throw new RuntimeException($this->sessionManager->lastError() ?? 'Unable to create tmux session.');
        }

        $this->sessionCreated = true;
        $this->configurePane($paneId, $role, $title);

        return $paneId;
    }

    private function createWindowPane(int $index, string $windowName, TmuxPaneRole $role, string $title): string
    {
        $paneId = $this->paneManager->createWindow($index, $windowName);
        if ($paneId === null) {
            throw new RuntimeException($this->paneManager->lastError() ?? "Unable to create tmux window {$index}.");
        }

        $this->configurePane($paneId, $role, $title);

        return $paneId;
    }

    private function splitHorizontal(string $target, int $percentage, TmuxPaneRole $role, string $title): string
    {
        $paneId = $this->paneManager->splitHorizontal($target, $percentage);
        if ($paneId === null) {
            throw new RuntimeException($this->paneManager->lastError() ?? "Unable to split tmux pane {$target}.");
        }

        $this->configurePane($paneId, $role, $title);

        return $paneId;
    }

    private function splitVertical(string $target, int $percentage, TmuxPaneRole $role, string $title): string
    {
        $paneId = $this->paneManager->splitVertical($target, $percentage);
        if ($paneId === null) {
            throw new RuntimeException($this->paneManager->lastError() ?? "Unable to split tmux pane {$target}.");
        }

        $this->configurePane($paneId, $role, $title);

        return $paneId;
    }

    /** @var list<TmuxPaneRole> */
    private array $expectedRoles = [];

    /** @var list<TmuxPaneRole> */
    private array $configuredRoles = [];

    private function configurePane(string $paneId, TmuxPaneRole $role, string $title): void
    {
        if ($role !== TmuxPaneRole::Monitor && in_array($role, array_values(TmuxMode::Full->tasks()), true) && ! in_array($role, $this->expectedRoles, true)) {
            throw new RuntimeException("Pane role '{$role->value}' is not scheduled in this mode.");
        }
        $this->requireSuccess($this->paneManager->retainPane($paneId), "retain {$role->value} pane");
        $this->requireSuccess($this->paneManager->setPaneTitle($paneId, $title), "set title for {$role->value} pane");
        $this->requireSuccess($this->paneManager->setPaneRole($paneId, $role), "tag {$role->value} pane");
        $this->configuredRoles[] = $role;
    }

    private function requireSuccess(bool $successful, string $operation): void
    {
        if (! $successful) {
            throw new RuntimeException(self::formatOperationError($operation, $this->paneManager->lastError()));
        }
    }

    /**
     * Check if a command exists
     */
    protected function commandExists(string $command): bool
    {
        $result = Process::timeout(5)
            ->run(['which', $command]);

        return $result->successful() && str_contains($result->output(), $command);
    }

    /**
     * Resolve how to connect to Redis from the host
     *
     * Returns an array with:
     * - 'use_sail' => bool - whether to use sail to run inside Docker
     * - 'override_host' => bool - whether to override REDIS_HOST env var
     * - 'host' => string - the host to use (only relevant if override_host is true)
     *
     * @return array<string, mixed>
     */
    protected function resolveRedisConnection(string $host, int $port): array
    {
        // If host is already an IP or localhost, use it directly
        if (filter_var($host, FILTER_VALIDATE_IP) || $host === 'localhost') {
            return ['use_sail' => false, 'override_host' => false, 'host' => $host];
        }

        // Try to resolve the hostname
        $resolved = gethostbyname($host);
        if ($resolved !== $host) {
            // Hostname resolves - use it directly
            return ['use_sail' => false, 'override_host' => false, 'host' => $host];
        }

        // Hostname doesn't resolve (Docker internal hostname)
        // Check if Redis is accessible on 127.0.0.1 (Docker port forwarding)
        $socket = @fsockopen('127.0.0.1', $port, $errno, $errstr, 1);
        if ($socket !== false) {
            fclose($socket);

            // Redis accessible on localhost - override host to 127.0.0.1
            return ['use_sail' => false, 'override_host' => true, 'host' => '127.0.0.1'];
        }

        // Redis not accessible on localhost - need to use sail
        return ['use_sail' => true, 'override_host' => false, 'host' => $host];
    }

    private static function ensureSlots(FFI $ffi): bool
    {
        if (self::$slotOwner !== $ffi || self::$int64Slot === null || self::$int32Slot === null) {
            try {
                self::$int64Slot = $ffi->new('int64_t[1]');
                self::$int32Slot = $ffi->new('int32_t[1]');
                self::$slotOwner = $ffi;
            } catch (Throwable) {
                return false;
            }
        }

        return true;
    }

    // ========================================================================
    // WAVE 58.4 BATCH HELPER
    // ========================================================================

    /** Enabled: the minimum of three paired runs cleared every 1.5x gate (common mean 8.29x, p99 10.67x; uncommon mean 15.40x, p99 15.60x); see RustTmuxLayoutMonitorOutputPaneSessionManagerW58ParityTest. */
    public const bool W58_RUST_FFI_DEFAULT = true;

    public const string W58_FFI_CDEF = <<<'C'
        ssize_t nntmux_w58_layout_plan_row_batch(const char *blob, size_t blob_len, const char *ints, size_t ints_len, size_t count, int64_t *out, size_t out_cap);
        C;

    /** int64 words per layoutPlanRowBatch() record. */
    public const int W58_ROW_WORDS = 11;

    /** int64 summary words after the layoutPlanRowBatch() records (none for an empty batch). */
    public const int W58_TRAILER_WORDS = 4;

    /**
     * Layout rows for tmux window_layout strings ("csum,WxH,X,Y" cells ending in ",ID" for a
     * pane, "{cells}" for a left-right split or "[cells]" for a top-bottom split; numbers are
     * 1-9 ASCII digits, nesting stops at depth 64) checked against the planCoreLayoutStepsPhp()
     * pane count of their window. Each item is [layout, sequential mode, window index] and yields
     * [status (-1 malformed, 0 valid, 1 checksum mismatch), width, height, panes, max pane depth,
     * left-right splits, top-bottom splits, min pane area, shape (0 single, 1 left-right, 2
     * top-bottom, 3 mixed; -1 malformed), expected panes, flags (1 pane count matches, 2
     * checksum, 4 increasing pane IDs, 8 children tile their parent, 16 root at the origin)],
     * followed by the trailer [valid, checksum ok, matching expected, total panes].
     *
     * @param  list<array{0: string, 1: int, 2: int}>  $items
     * @return list<int>
     */
    public static function layoutPlanRowBatch(array $items): array
    {
        return self::usesW58RustFfi() ? self::layoutPlanRowBatchNative($items) : self::layoutPlanRowBatchPhp($items);
    }

    /**
     * @param  list<array{0: string, 1: int, 2: int}>  $items
     * @return list<int>
     */
    public static function layoutPlanRowBatchPhp(array $items): array
    {
        self::w58Items($items);
        $out = [];
        $summary = [0, 0, 0, 0];
        foreach ($items as [$layout, $mode, $window]) {
            $expected = self::w58ExpectedPanes($mode, $window);
            $parsed = self::w58ParseLayout($layout);
            if ($parsed === null) {
                array_push($out, -1, 0, 0, 0, 0, 0, 0, 0, -1, $expected, 0);

                continue;
            }
            [$root, $stats, $checksum] = $parsed;
            $matches = $stats['panes'] === $expected;
            array_push(
                $out,
                $checksum ? 0 : 1,
                $root[0],
                $root[1],
                $stats['panes'],
                $stats['depth'],
                $stats['h'],
                $stats['v'],
                $stats['min'],
                ($stats['h'] > 0 ? 1 : 0) | ($stats['v'] > 0 ? 2 : 0),
                $expected,
                ($matches ? 1 : 0) | ($checksum ? 2 : 0) | ($stats['increasing'] ? 4 : 0) | ($stats['geometry'] ? 8 : 0)
                    | ($root[2] === 0 && $root[3] === 0 ? 16 : 0),
            );
            $summary[0]++;
            $summary[1] += $checksum ? 1 : 0;
            $summary[2] += $matches ? 1 : 0;
            $summary[3] += $stats['panes'];
        }

        return $items === [] ? [] : [...$out, ...$summary];
    }

    /**
     * @param  list<array{0: string, 1: int, 2: int}>  $items
     * @return list<int>
     */
    public static function layoutPlanRowBatchNative(array $items): array
    {
        self::w58Items($items);
        $count = \count($items);
        if ($count === 0) {
            return [];
        }
        $blob = '';
        $lens = [];
        $modes = [];
        $windows = [];
        foreach ($items as [$layout, $mode, $window]) {
            $blob .= $layout;
            $lens[] = \strlen($layout);
            $modes[] = $mode;
            $windows[] = $window;
        }

        return self::w58Call($blob, pack('P*', ...$lens, ...$modes, ...$windows), $count, $count * self::W58_ROW_WORDS + self::W58_TRAILER_WORDS)
            ?? self::layoutPlanRowBatchPhp($items);
    }

    /** Panes planCoreLayoutStepsPhp() puts in a window; unknown modes plan as Full. */
    private static function w58ExpectedPanes(int $mode, int $window): int
    {
        /** @var array<int, array<int, int>>|null $counts */
        static $counts = null;
        if ($counts === null) {
            $counts = [];
            foreach (TmuxMode::cases() as $case) {
                foreach (self::planCoreLayoutStepsPhp($case->value) as $step) {
                    $counts[$case->value][$step['window']] = ($counts[$case->value][$step['window']] ?? 0) + 1;
                }
            }
        }

        return $counts[(TmuxMode::tryFrom($mode) ?? TmuxMode::Full)->value][$window] ?? 0;
    }

    /**
     * @return array{0: array{0: int, 1: int, 2: int, 3: int}, 1: array{panes: int, depth: int, h: int, v: int, min: int, last: int, increasing: bool, geometry: bool}, 2: bool}|null
     */
    private static function w58ParseLayout(string $layout): ?array
    {
        $length = \strlen($layout);
        if ($length < 6 || $layout[4] !== ',' || strspn($layout, '0123456789abcdefABCDEF', 0, 4) !== 4) {
            return null;
        }
        $stats = ['panes' => 0, 'depth' => 0, 'h' => 0, 'v' => 0, 'min' => PHP_INT_MAX, 'last' => -1, 'increasing' => true, 'geometry' => true];
        $pos = 5;
        $root = self::w58LayoutCell($layout, $pos, 0, $stats);
        if ($root === null || $pos !== $length) {
            return null;
        }
        // tmux layout_checksum() over the body after "csum,".
        $sum = 0;
        for ($i = 5; $i < $length; $i++) {
            $sum = (($sum >> 1) + (($sum & 1) << 15) + \ord($layout[$i])) & 0xFFFF;
        }

        return [$root, $stats, hexdec(substr($layout, 0, 4)) === $sum];
    }

    /**
     * @param  array{panes: int, depth: int, h: int, v: int, min: int, last: int, increasing: bool, geometry: bool}  $stats
     * @return array{0: int, 1: int, 2: int, 3: int}|null [width, height, x, y]
     */
    private static function w58LayoutCell(string $s, int &$pos, int $depth, array &$stats): ?array
    {
        if ($depth > 64) {
            return null;
        }
        $w = self::w58Digits($s, $pos);
        if ($w === null || ($s[$pos] ?? '') !== 'x') {
            return null;
        }
        $pos++;
        $h = self::w58Digits($s, $pos);
        if ($h === null || ($s[$pos] ?? '') !== ',') {
            return null;
        }
        $pos++;
        $x = self::w58Digits($s, $pos);
        if ($x === null || ($s[$pos] ?? '') !== ',') {
            return null;
        }
        $pos++;
        $y = self::w58Digits($s, $pos);
        $open = $s[$pos] ?? '';
        if ($y === null || $open === '') {
            return null;
        }
        $pos++;
        if ($open === ',') {
            $id = self::w58Digits($s, $pos);
            if ($id === null) {
                return null;
            }
            $stats['panes']++;
            $stats['depth'] = max($stats['depth'], $depth);
            $stats['min'] = min($stats['min'], $w * $h);
            if ($id <= $stats['last']) {
                $stats['increasing'] = false;
            }
            $stats['last'] = $id;

            return [$w, $h, $x, $y];
        }
        if ($open !== '{' && $open !== '[') {
            return null;
        }
        $horizontal = $open === '{';
        $close = $horizontal ? '}' : ']';
        $prev = null;
        while (true) {
            $c = self::w58LayoutCell($s, $pos, $depth + 1, $stats);
            if ($c === null) {
                return null;
            }
            $tiles = $horizontal
                ? $c[1] === $h && $c[3] === $y && ($prev === null ? $c[2] === $x : $c[2] === $prev[2] + $prev[0] + 1)
                : $c[0] === $w && $c[2] === $x && ($prev === null ? $c[3] === $y : $c[3] === $prev[3] + $prev[1] + 1);
            if (! $tiles) {
                $stats['geometry'] = false;
            }
            $prev = $c;
            $next = $s[$pos] ?? '';
            if ($next === '') {
                return null;
            }
            $pos++;
            if ($next === $close) {
                break;
            }
            if ($next !== ',') {
                return null;
            }
        }
        if ($horizontal ? $x + $w !== $prev[2] + $prev[0] : $y + $h !== $prev[3] + $prev[1]) {
            $stats['geometry'] = false;
        }
        if ($horizontal) {
            $stats['h']++;
        } else {
            $stats['v']++;
        }

        return [$w, $h, $x, $y];
    }

    /** 1-9 ASCII digits at $pos, advancing it. */
    private static function w58Digits(string $s, int &$pos): ?int
    {
        $n = strspn($s, '0123456789', $pos, 9);
        if ($n === 0) {
            return null;
        }
        $value = (int) substr($s, $pos, $n);
        $pos += $n;

        return $value;
    }

    public static function usesW58RustFfi(): bool
    {
        $enabled = self::w58RustFfiState('enabled');

        return \is_bool($enabled) ? $enabled : self::W58_RUST_FFI_DEFAULT;
    }

    /**
     * Run $callback with the W58 dispatch forced on or off, then restore the previous choice.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW58RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w58RustFfiState('enabled');
        self::w58RustFfiState('enabled', $enabled);
        try {
            return $callback();
        } finally {
            self::w58RustFfiState('enabled', $previous);
        }
    }

    /** @param array<array-key, mixed> $items */
    private static function w58Items(array $items): void
    {
        if (! array_is_list($items)) {
            throw new \InvalidArgumentException('W58 layout batch items must be a list.');
        }
        foreach ($items as $item) {
            if (! \is_array($item) || ! array_is_list($item) || \count($item) !== 3 || ! \is_string($item[0]) || ! \is_int($item[1]) || ! \is_int($item[2])) {
                throw new \InvalidArgumentException('W58 layout batch items must be [string layout, int mode, int window] lists.');
            }
        }
    }

    /**
     * One FFI crossing for the batch: $need int64 words back. Null when the library is missing
     * or declines a malformed frame, so the caller falls back to PHP; a declined call with a
     * resolved library is counted in the 'misses' state.
     *
     * @return list<int>|null
     */
    private static function w58Call(string $blob, string $ints, int $count, int $need): ?array
    {
        /** @var array{enabled: ?bool, ffi: FFI|false|null, out: ?CData, cap: int, misses: int} $state */
        $state = self::w58RustFfiState();
        $ffi = $state['ffi'];
        if ($ffi === null) {
            $ffi = RustPipelineLibrary::resolveDomain('metadata_w58', self::W58_FFI_CDEF) ?? false;
            self::w58RustFfiState('ffi', $ffi);
        }
        if (! $ffi instanceof FFI) {
            return null;
        }
        try {
            $out = $state['out'];
            $cap = $state['cap'];
            if (! $out instanceof CData || $cap < $need) {
                $cap = max(4096, $need);
                $out = $ffi->new('int64_t['.$cap.']');
                self::w58RustFfiState('out', $out);
                self::w58RustFfiState('cap', $cap);
            }
            // @phpstan-ignore method.notFound (Declared by W58_FFI_CDEF.)
            $written = $ffi->nntmux_w58_layout_plan_row_batch($blob, \strlen($blob), $ints, \strlen($ints), $count, $out, $cap);
            $values = $written === $need ? unpack('P*', FFI::string($out, $need * 8)) : false;
            if ($values !== false) {
                return array_values($values);
            }
        } catch (Throwable) {
        }
        self::w58RustFfiState('misses', $state['misses'] + 1);

        return null;
    }

    /**
     * W58 routing override, cached binding, reusable output buffer and declined-call count, kept
     * in a function static: no arguments returns the state, one argument reads a key ('reset'
     * clears everything) and two arguments write a key.
     */
    private static function w58RustFfiState(?string $op = null, mixed $val = null): mixed
    {
        /** @var array{enabled: ?bool, ffi: FFI|false|null, out: ?CData, cap: int, misses: int} $state */
        static $state = ['enabled' => null, 'ffi' => null, 'out' => null, 'cap' => 0, 'misses' => 0];
        if ($op === null) {
            return $state;
        }
        if ($op === 'reset') {
            $state = ['enabled' => null, 'ffi' => null, 'out' => null, 'cap' => 0, 'misses' => 0];

            return null;
        }
        if (\func_num_args() > 1) {
            $state[$op] = $val;
        }

        return $state[$op] ?? null;
    }
}
