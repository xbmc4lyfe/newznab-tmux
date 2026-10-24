<?php

declare(strict_types=1);

namespace App\Support\Configuration;

use App\Models\TmuxCleanupRule;
use App\Models\TmuxColorExclusion;
use App\Models\TmuxConfiguration;
use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
use Throwable;

final readonly class TmuxConfigurationData
{
    public const string FFI_CDEF = <<<'C'
        ssize_t nntmux_tmux_config_defaults(int64_t *out_scalars, size_t scalars_cap, int64_t *out_exclusions, size_t exclusions_cap, char *out_buf, size_t out_cap);
        ssize_t nntmux_tmux_config_normalize_scalars(const int64_t *raw_ptr, size_t count, int64_t *out_ptr, size_t out_cap);
        ssize_t nntmux_tmux_config_normalize_color_exclusions(const int64_t *raw_ptr, size_t count, int64_t *out_ptr, size_t out_cap);
        ssize_t nntmux_tmux_config_normalize_cleanup_rules_nul(const char *input_ptr, size_t input_len, size_t count, char *out_buf, size_t out_cap);
        C;

    /** Property names in declaration order (the `toArray()` keys). */
    public const array FIELDS = [
        'sessionName', 'monitorDelay', 'niceness', 'sequentialMode', 'sequentialTimer',
        'binariesEnabled', 'binariesTimer', 'binariesKillTimer', 'backfillMode', 'backfillGroups',
        'backfillTimer', 'progressiveBackfill', 'releasesEnabled', 'releaseTimer', 'postMode',
        'postTimer', 'postKillTimer', 'postAmazonMode', 'postAmazonTimer', 'postNonMode',
        'postNonTimer', 'fixNamesEnabled', 'fixTimer', 'cleanupMode', 'cleanupTimer',
        'runIrcScraper', 'consoleEnabled', 'htopEnabled', 'mytopEnabled', 'nmonEnabled',
        'vnstatEnabled', 'vnstatArgs', 'tcpTrackEnabled', 'tcpTrackArgs', 'bwmngEnabled',
        'redisEnabled', 'redisArgs', 'writeLogs', 'collectionsKillThreshold',
        'postProcessKillThreshold', 'colorsStart', 'colorsEnd', 'cleanupRules', 'colorExclusions',
    ];

    private const int SCALAR_COUNT = 37;

    private const int DEFAULTS_SCALAR_LEN = 40;

    private const int DEFAULT_EXCLUSIONS_LEN = 19;

    /**
     * @param  list<string>  $cleanupRules
     * @param  list<int>  $colorExclusions
     */
    public function __construct(
        public string $sessionName,
        public int $monitorDelay,
        public int $niceness,
        public int $sequentialMode,
        public int $sequentialTimer,
        public bool $binariesEnabled,
        public int $binariesTimer,
        public int $binariesKillTimer,
        public int $backfillMode,
        public int $backfillGroups,
        public int $backfillTimer,
        public bool $progressiveBackfill,
        public bool $releasesEnabled,
        public int $releaseTimer,
        public int $postMode,
        public int $postTimer,
        public int $postKillTimer,
        public int $postAmazonMode,
        public int $postAmazonTimer,
        public int $postNonMode,
        public int $postNonTimer,
        public bool $fixNamesEnabled,
        public int $fixTimer,
        public string $cleanupMode,
        public int $cleanupTimer,
        public bool $runIrcScraper,
        public bool $consoleEnabled,
        public bool $htopEnabled,
        public bool $mytopEnabled,
        public bool $nmonEnabled,
        public bool $vnstatEnabled,
        public ?string $vnstatArgs,
        public bool $tcpTrackEnabled,
        public ?string $tcpTrackArgs,
        public bool $bwmngEnabled,
        public bool $redisEnabled,
        public ?string $redisArgs,
        public bool $writeLogs,
        public int $collectionsKillThreshold,
        public int $postProcessKillThreshold,
        public int $colorsStart,
        public int $colorsEnd,
        public array $cleanupRules,
        public array $colorExclusions,
    ) {}

    public static function setUseRustFfi(?bool $use = true): void
    {
        self::toggleState($use, true);
        if ($use === null) {
            self::categorizeFfi(true);
        }
    }

    public static function usesRustFfi(): bool
    {
        return self::toggleState() ?? false;
    }

    public static function setFfiForTesting(FFI|false|null $override = null): void
    {
        self::categorizeFfi(true, $override);
    }

    private static function toggleState(?bool $next = null, bool $write = false): ?bool
    {
        static $useRustFfi = null;
        if ($write) {
            $useRustFfi = $next;
        }

        return $useRustFfi;
    }

    public static function categorizeFfi(bool $reset = false, FFI|false|null $override = null): ?FFI
    {
        static $ffi = null;
        if ($reset) {
            $ffi = $override;
            if ($override !== null) {
                return $ffi === false ? null : $ffi;
            }
        }
        if ($ffi === null) {
            $ffi = RustPipelineLibrary::resolveDomain('categorize', self::FFI_CDEF) ?? false;
        }

        return $ffi === false ? null : $ffi;
    }

    public static function defaults(): self
    {
        return self::usesRustFfi() ? self::defaultsNative() : self::defaultsPhp();
    }

    public static function defaultsPhp(): self
    {
        return new self('nntmux', 30, 19, 0, 30, false, 30, 1, 0, 4, 30, false, false, 30, 0, 30, 300, 0, 30, 0, 30, false, 30, 'Disabled', 30, false, false, false, false, false, false, null, false, '-i eth0 port 443', false, false, null, false, 0, 0, 1, 250, [], [4, 8, 9, 11, 15, 16, 17, 18, 19, 46, 47, 48, 49, 50, 51, 52, 53, 59, 60]);
    }

    public static function defaultsNative(): self
    {
        $ffi = self::categorizeFfi();
        $buffers = $ffi === null ? null : self::buffers($ffi);
        if ($ffi === null || $buffers === null) {
            return self::defaultsPhp();
        }
        [$scalarsBuf, $exclusionsBuf] = $buffers;
        $cap = 0;
        $scratch = RustPipelineLibrary::obtainScratch($ffi, 128, $cap);
        if ($scratch === null) {
            return self::defaultsPhp();
        }

        try {
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_tmux_config_defaults(
                $scalarsBuf,
                self::DEFAULTS_SCALAR_LEN,
                $exclusionsBuf,
                self::DEFAULT_EXCLUSIONS_LEN,
                $scratch,
                $cap
            );
            if (\is_int($written) && $written < -1) {
                $scratch = RustPipelineLibrary::obtainScratch($ffi, -$written, $cap);
                if ($scratch === null) {
                    return self::defaultsPhp();
                }
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_tmux_config_defaults(
                    $scalarsBuf,
                    self::DEFAULTS_SCALAR_LEN,
                    $exclusionsBuf,
                    self::DEFAULT_EXCLUSIONS_LEN,
                    $scratch,
                    $cap
                );
            }
            if (! \is_int($written) || $written <= 0 || $written > $cap) {
                return self::defaultsPhp();
            }

            $scalarsRaw = unpack('q'.self::DEFAULTS_SCALAR_LEN, FFI::string($scalarsBuf, self::DEFAULTS_SCALAR_LEN * 8));
            $exclusionsRaw = unpack('q'.self::DEFAULT_EXCLUSIONS_LEN, FFI::string($exclusionsBuf, self::DEFAULT_EXCLUSIONS_LEN * 8));
            $parts = explode("\0", FFI::string($scratch, $written));
        } catch (Throwable) {
            return self::defaultsPhp();
        }

        if (! \is_array($scalarsRaw) || \count($scalarsRaw) !== self::DEFAULTS_SCALAR_LEN
            || ! \is_array($exclusionsRaw) || \count($exclusionsRaw) !== self::DEFAULT_EXCLUSIONS_LEN
            || \count($parts) !== 5) {
            return self::defaultsPhp();
        }

        /** @var list<int> $scalars */
        $scalars = array_map(static fn (mixed $v): int => (int) $v, array_values($scalarsRaw));
        /** @var list<int> $exclusions */
        $exclusions = array_map(static fn (mixed $v): int => (int) $v, array_values($exclusionsRaw));

        return self::buildFromDecoded(
            $scalars,
            $parts[0],
            $parts[1],
            $scalars[37] !== 0 ? $parts[2] : null,
            $scalars[38] !== 0 ? $parts[3] : null,
            $scalars[39] !== 0 ? $parts[4] : null,
            [],
            $exclusions,
        );
    }

    public static function fromModel(TmuxConfiguration $model): self
    {
        return self::usesRustFfi() ? self::fromModelNative($model) : self::fromModelPhp($model);
    }

    public static function fromModelPhp(TmuxConfiguration $model): self
    {
        return new self(
            (string) $model->session_name,
            (int) $model->monitor_delay,
            (int) $model->niceness,
            $model->sequential_mode->value,
            (int) $model->sequential_timer,
            (bool) $model->binaries_enabled,
            (int) $model->binaries_timer,
            (int) $model->binaries_kill_timer,
            $model->backfill_mode->value,
            (int) $model->backfill_groups,
            (int) $model->backfill_timer,
            (bool) $model->progressive_backfill,
            (bool) $model->releases_enabled,
            (int) $model->release_timer,
            $model->post_mode->value,
            (int) $model->post_timer,
            (int) $model->post_kill_timer,
            $model->post_amazon_mode->value,
            (int) $model->post_amazon_timer,
            $model->post_non_mode->value,
            (int) $model->post_non_timer,
            (bool) $model->fix_names_enabled,
            (int) $model->fix_timer,
            (string) $model->cleanup_mode,
            (int) $model->cleanup_timer,
            (bool) $model->run_irc_scraper,
            (bool) $model->console_enabled,
            (bool) $model->htop_enabled,
            (bool) $model->mytop_enabled,
            (bool) $model->nmon_enabled,
            (bool) $model->vnstat_enabled,
            $model->vnstat_args === null ? null : (string) $model->vnstat_args,
            (bool) $model->tcp_track_enabled,
            $model->tcp_track_args === null ? null : (string) $model->tcp_track_args,
            (bool) $model->bwmng_enabled,
            (bool) $model->redis_enabled,
            $model->redis_args === null ? null : (string) $model->redis_args,
            (bool) $model->write_logs,
            (int) $model->collections_kill_threshold,
            (int) $model->post_process_kill_threshold,
            (int) $model->colors_start,
            (int) $model->colors_end,
            self::normalizeCleanupRulesPhp($model->cleanupRules),
            self::normalizeColorExclusionsPhp($model->colorExclusions),
        );
    }

    public static function fromModelNative(TmuxConfiguration $model): self
    {
        $ffi = self::categorizeFfi();
        $buffers = $ffi === null ? null : self::buffers($ffi);
        if ($ffi === null || $buffers === null) {
            return self::fromModelPhp($model);
        }
        [$scalarsBuf] = $buffers;

        $rawScalars = [
            (int) $model->monitor_delay,
            (int) $model->niceness,
            $model->sequential_mode->value,
            (int) $model->sequential_timer,
            (int) (bool) $model->binaries_enabled,
            (int) $model->binaries_timer,
            (int) $model->binaries_kill_timer,
            $model->backfill_mode->value,
            (int) $model->backfill_groups,
            (int) $model->backfill_timer,
            (int) (bool) $model->progressive_backfill,
            (int) (bool) $model->releases_enabled,
            (int) $model->release_timer,
            $model->post_mode->value,
            (int) $model->post_timer,
            (int) $model->post_kill_timer,
            $model->post_amazon_mode->value,
            (int) $model->post_amazon_timer,
            $model->post_non_mode->value,
            (int) $model->post_non_timer,
            (int) (bool) $model->fix_names_enabled,
            (int) $model->fix_timer,
            (int) $model->cleanup_timer,
            (int) (bool) $model->run_irc_scraper,
            (int) (bool) $model->console_enabled,
            (int) (bool) $model->htop_enabled,
            (int) (bool) $model->mytop_enabled,
            (int) (bool) $model->nmon_enabled,
            (int) (bool) $model->vnstat_enabled,
            (int) (bool) $model->tcp_track_enabled,
            (int) (bool) $model->bwmng_enabled,
            (int) (bool) $model->redis_enabled,
            (int) (bool) $model->write_logs,
            (int) $model->collections_kill_threshold,
            (int) $model->post_process_kill_threshold,
            (int) $model->colors_start,
            (int) $model->colors_end,
        ];

        try {
            FFI::memcpy($scalarsBuf, pack('q*', ...$rawScalars), self::SCALAR_COUNT * 8);
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_tmux_config_normalize_scalars($scalarsBuf, self::SCALAR_COUNT, $scalarsBuf, self::SCALAR_COUNT);
            if ($written !== self::SCALAR_COUNT) {
                return self::fromModelPhp($model);
            }
            $scalarsRaw = unpack('q'.self::SCALAR_COUNT, FFI::string($scalarsBuf, self::SCALAR_COUNT * 8));
        } catch (Throwable) {
            return self::fromModelPhp($model);
        }

        if (! \is_array($scalarsRaw) || \count($scalarsRaw) !== self::SCALAR_COUNT) {
            return self::fromModelPhp($model);
        }

        /** @var list<int> $scalars */
        $scalars = array_map(static fn (mixed $v): int => (int) $v, array_values($scalarsRaw));

        return self::buildFromDecoded(
            $scalars,
            (string) $model->session_name,
            (string) $model->cleanup_mode,
            $model->vnstat_args === null ? null : (string) $model->vnstat_args,
            $model->tcp_track_args === null ? null : (string) $model->tcp_track_args,
            $model->redis_args === null ? null : (string) $model->redis_args,
            self::normalizeCleanupRulesNative($model->cleanupRules),
            self::normalizeColorExclusionsNative($model->colorExclusions),
        );
    }

    /**
     * @param  iterable<int, mixed>  $rules
     * @return list<string>
     */
    public static function normalizeCleanupRules(iterable $rules): array
    {
        return self::usesRustFfi() ? self::normalizeCleanupRulesNative($rules) : self::normalizeCleanupRulesPhp($rules);
    }

    /**
     * @param  iterable<int, mixed>  $rules
     * @return list<string>
     */
    public static function normalizeCleanupRulesPhp(iterable $rules): array
    {
        $out = [];
        foreach ($rules as $item) {
            if ($item instanceof TmuxCleanupRule) {
                $raw = $item->rule;
            } elseif (\is_array($item) && \array_key_exists('rule', $item)) {
                $raw = $item['rule'];
            } elseif (\is_object($item) && isset($item->rule)) {
                $raw = $item->rule;
            } else {
                $raw = $item;
            }
            $out[] = \is_scalar($raw) || $raw === null ? (string) $raw : '';
        }

        return $out;
    }

    /**
     * @param  iterable<int, mixed>  $rules
     * @return list<string>
     */
    public static function normalizeCleanupRulesNative(iterable $rules): array
    {
        $extracted = self::normalizeCleanupRulesPhp($rules);
        $count = \count($extracted);
        if ($count === 0) {
            return [];
        }

        $ffi = self::categorizeFfi();
        if ($ffi === null) {
            return $extracted;
        }

        $joined = implode("\0", $extracted);
        if (substr_count($joined, "\0") !== $count - 1) {
            return $extracted;
        }

        $len = \strlen($joined);
        $cap = 0;
        $scratch = RustPipelineLibrary::obtainScratch($ffi, max(128, $len + 32), $cap);
        if ($scratch === null) {
            return $extracted;
        }

        try {
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_tmux_config_normalize_cleanup_rules_nul($joined, $len, $count, $scratch, $cap);
            if (\is_int($written) && $written < -1) {
                $scratch = RustPipelineLibrary::obtainScratch($ffi, -$written, $cap);
                if ($scratch === null) {
                    return $extracted;
                }
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_tmux_config_normalize_cleanup_rules_nul($joined, $len, $count, $scratch, $cap);
            }
            if (! \is_int($written) || $written < 0 || $written > $cap) {
                return $extracted;
            }
            if ($written === 0) {
                return array_fill(0, $count, '');
            }
            $parts = explode("\0", FFI::string($scratch, $written));

            return \count($parts) === $count ? $parts : $extracted;
        } catch (Throwable) {
            return $extracted;
        }
    }

    /**
     * @param  iterable<int, mixed>  $colors
     * @return list<int>
     */
    public static function normalizeColorExclusions(iterable $colors): array
    {
        return self::usesRustFfi() ? self::normalizeColorExclusionsNative($colors) : self::normalizeColorExclusionsPhp($colors);
    }

    /**
     * @param  iterable<int, mixed>  $colors
     * @return list<int>
     */
    public static function normalizeColorExclusionsPhp(iterable $colors): array
    {
        $out = [];
        foreach ($colors as $item) {
            if ($item instanceof TmuxColorExclusion) {
                $raw = $item->color;
            } elseif (\is_array($item) && \array_key_exists('color', $item)) {
                $raw = $item['color'];
            } elseif (\is_object($item) && isset($item->color)) {
                $raw = $item->color;
            } else {
                $raw = $item;
            }
            $out[] = \is_numeric($raw) || \is_bool($raw) ? (int) $raw : 0;
        }

        return $out;
    }

    /**
     * @param  iterable<int, mixed>  $colors
     * @return list<int>
     */
    public static function normalizeColorExclusionsNative(iterable $colors): array
    {
        $extracted = self::normalizeColorExclusionsPhp($colors);
        $count = \count($extracted);
        if ($count === 0) {
            return [];
        }

        $ffi = self::categorizeFfi();
        if ($ffi === null) {
            return $extracted;
        }

        try {
            $buf = $ffi->new("int64_t[{$count}]");
            FFI::memcpy($buf, pack('q*', ...$extracted), $count * 8);
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_tmux_config_normalize_color_exclusions($buf, $count, $buf, $count);
            if ($written !== $count) {
                return $extracted;
            }
            $unpacked = unpack('q'.$count, FFI::string($buf, $count * 8));
            if (! \is_array($unpacked) || \count($unpacked) !== $count) {
                return $extracted;
            }

            /** @var list<int> $normalized */
            $normalized = array_map(static fn (mixed $v): int => (int) $v, array_values($unpacked));

            return $normalized;
        } catch (Throwable) {
            return $extracted;
        }
    }

    /** @return array<string, int|bool|string|array<int, int|string>|null> */
    public function toArray(): array
    {
        return self::usesRustFfi() ? $this->toArrayNative() : $this->toArrayPhp();
    }

    /** @return array<string, int|bool|string|array<int, int|string>|null> */
    public function toArrayPhp(): array
    {
        return get_object_vars($this);
    }

    /** @return array<string, int|bool|string|array<int, int|string>|null> */
    public function toArrayNative(): array
    {
        $ffi = self::categorizeFfi();
        $buffers = $ffi === null ? null : self::buffers($ffi);
        if ($ffi === null || $buffers === null) {
            return $this->toArrayPhp();
        }
        [$scalarsBuf] = $buffers;

        $rawScalars = [
            $this->monitorDelay,
            $this->niceness,
            $this->sequentialMode,
            $this->sequentialTimer,
            (int) $this->binariesEnabled,
            $this->binariesTimer,
            $this->binariesKillTimer,
            $this->backfillMode,
            $this->backfillGroups,
            $this->backfillTimer,
            (int) $this->progressiveBackfill,
            (int) $this->releasesEnabled,
            $this->releaseTimer,
            $this->postMode,
            $this->postTimer,
            $this->postKillTimer,
            $this->postAmazonMode,
            $this->postAmazonTimer,
            $this->postNonMode,
            $this->postNonTimer,
            (int) $this->fixNamesEnabled,
            $this->fixTimer,
            $this->cleanupTimer,
            (int) $this->runIrcScraper,
            (int) $this->consoleEnabled,
            (int) $this->htopEnabled,
            (int) $this->mytopEnabled,
            (int) $this->nmonEnabled,
            (int) $this->vnstatEnabled,
            (int) $this->tcpTrackEnabled,
            (int) $this->bwmngEnabled,
            (int) $this->redisEnabled,
            (int) $this->writeLogs,
            $this->collectionsKillThreshold,
            $this->postProcessKillThreshold,
            $this->colorsStart,
            $this->colorsEnd,
        ];

        try {
            FFI::memcpy($scalarsBuf, pack('q*', ...$rawScalars), self::SCALAR_COUNT * 8);
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_tmux_config_normalize_scalars($scalarsBuf, self::SCALAR_COUNT, $scalarsBuf, self::SCALAR_COUNT);
            if ($written !== self::SCALAR_COUNT) {
                return $this->toArrayPhp();
            }
            $scalarsRaw = unpack('q'.self::SCALAR_COUNT, FFI::string($scalarsBuf, self::SCALAR_COUNT * 8));
        } catch (Throwable) {
            return $this->toArrayPhp();
        }

        if (! \is_array($scalarsRaw) || \count($scalarsRaw) !== self::SCALAR_COUNT) {
            return $this->toArrayPhp();
        }

        /** @var list<int> $scalars */
        $scalars = array_map(static fn (mixed $v): int => (int) $v, array_values($scalarsRaw));
        $values = self::decodeFieldList(
            $scalars,
            $this->sessionName,
            $this->cleanupMode,
            $this->vnstatArgs,
            $this->tcpTrackArgs,
            $this->redisArgs,
            $this->cleanupRules,
            $this->colorExclusions,
        );

        return array_combine(self::FIELDS, $values);
    }

    /**
     * @param  list<int>  $scalars
     * @param  list<string>  $cleanupRules
     * @param  list<int>  $colorExclusions
     */
    private static function buildFromDecoded(
        array $scalars,
        string $sessionName,
        string $cleanupMode,
        ?string $vnstatArgs,
        ?string $tcpTrackArgs,
        ?string $redisArgs,
        array $cleanupRules,
        array $colorExclusions,
    ): self {
        return new self(
            $sessionName,
            $scalars[0],
            $scalars[1],
            $scalars[2],
            $scalars[3],
            $scalars[4] !== 0,
            $scalars[5],
            $scalars[6],
            $scalars[7],
            $scalars[8],
            $scalars[9],
            $scalars[10] !== 0,
            $scalars[11] !== 0,
            $scalars[12],
            $scalars[13],
            $scalars[14],
            $scalars[15],
            $scalars[16],
            $scalars[17],
            $scalars[18],
            $scalars[19],
            $scalars[20] !== 0,
            $scalars[21],
            $cleanupMode,
            $scalars[22],
            $scalars[23] !== 0,
            $scalars[24] !== 0,
            $scalars[25] !== 0,
            $scalars[26] !== 0,
            $scalars[27] !== 0,
            $scalars[28] !== 0,
            $vnstatArgs,
            $scalars[29] !== 0,
            $tcpTrackArgs,
            $scalars[30] !== 0,
            $scalars[31] !== 0,
            $redisArgs,
            $scalars[32] !== 0,
            $scalars[33],
            $scalars[34],
            $scalars[35],
            $scalars[36],
            $cleanupRules,
            $colorExclusions,
        );
    }

    /**
     * @param  list<int>  $scalars
     * @param  list<string>  $cleanupRules
     * @param  list<int>  $colorExclusions
     * @return list<int|bool|string|array<int, int|string>|null>
     */
    private static function decodeFieldList(
        array $scalars,
        string $sessionName,
        string $cleanupMode,
        ?string $vnstatArgs,
        ?string $tcpTrackArgs,
        ?string $redisArgs,
        array $cleanupRules,
        array $colorExclusions,
    ): array {
        return [
            $sessionName,
            $scalars[0],
            $scalars[1],
            $scalars[2],
            $scalars[3],
            $scalars[4] !== 0,
            $scalars[5],
            $scalars[6],
            $scalars[7],
            $scalars[8],
            $scalars[9],
            $scalars[10] !== 0,
            $scalars[11] !== 0,
            $scalars[12],
            $scalars[13],
            $scalars[14],
            $scalars[15],
            $scalars[16],
            $scalars[17],
            $scalars[18],
            $scalars[19],
            $scalars[20] !== 0,
            $scalars[21],
            $cleanupMode,
            $scalars[22],
            $scalars[23] !== 0,
            $scalars[24] !== 0,
            $scalars[25] !== 0,
            $scalars[26] !== 0,
            $scalars[27] !== 0,
            $scalars[28] !== 0,
            $vnstatArgs,
            $scalars[29] !== 0,
            $tcpTrackArgs,
            $scalars[30] !== 0,
            $scalars[31] !== 0,
            $redisArgs,
            $scalars[32] !== 0,
            $scalars[33],
            $scalars[34],
            $scalars[35],
            $scalars[36],
            $cleanupRules,
            $colorExclusions,
        ];
    }

    /**
     * @return array{0: CData, 1: CData}|null
     */
    private static function buffers(FFI $ffi): ?array
    {
        static $buffers = null;
        static $owner = null;
        if ($owner !== $ffi || $buffers === null) {
            try {
                $scalars = $ffi->new('int64_t['.self::DEFAULTS_SCALAR_LEN.']');
                $exclusions = $ffi->new('int64_t['.self::DEFAULT_EXCLUSIONS_LEN.']');
                $buffers = [$scalars, $exclusions];
                $owner = $ffi;
            } catch (Throwable) {
                return null;
            }
        }

        return $buffers;
    }
}
