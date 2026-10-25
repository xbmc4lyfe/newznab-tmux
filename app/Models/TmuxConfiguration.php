<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TmuxBackfillMode;
use App\Enums\TmuxPostProcessMode;
use App\Enums\TmuxSequentialMode;
use App\Models\Concerns\IsSingletonConfiguration;
use App\Support\RustPipelineLibrary;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Throwable;

/**
 * @property string $session_name
 * @property int $monitor_delay
 * @property int $niceness
 * @property TmuxSequentialMode $sequential_mode
 * @property int $sequential_timer
 * @property bool $binaries_enabled
 * @property int $binaries_timer
 * @property int $binaries_kill_timer
 * @property TmuxBackfillMode $backfill_mode
 * @property int $backfill_groups
 * @property int $backfill_timer
 * @property bool $progressive_backfill
 * @property bool $releases_enabled
 * @property int $release_timer
 * @property TmuxPostProcessMode $post_mode
 * @property int $post_timer
 * @property int $post_kill_timer
 * @property TmuxPostProcessMode $post_amazon_mode
 * @property int $post_amazon_timer
 * @property TmuxPostProcessMode $post_non_mode
 * @property int $post_non_timer
 * @property bool $fix_names_enabled
 * @property int $fix_timer
 * @property string $cleanup_mode
 * @property int $cleanup_timer
 * @property bool $run_irc_scraper
 * @property bool $console_enabled
 * @property bool $htop_enabled
 * @property bool $mytop_enabled
 * @property bool $nmon_enabled
 * @property bool $vnstat_enabled
 * @property string|null $vnstat_args
 * @property bool $tcp_track_enabled
 * @property string|null $tcp_track_args
 * @property bool $bwmng_enabled
 * @property bool $redis_enabled
 * @property string|null $redis_args
 * @property bool $write_logs
 * @property int $collections_kill_threshold
 * @property int $post_process_kill_threshold
 * @property int $colors_start
 * @property int $colors_end
 * @property-read Collection<int, TmuxCleanupRule> $cleanupRules
 * @property-read Collection<int, TmuxColorExclusion> $colorExclusions
 */
final class TmuxConfiguration extends Model
{
    use IsSingletonConfiguration;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'sequential_mode' => TmuxSequentialMode::class,
            'backfill_mode' => TmuxBackfillMode::class,
            'post_mode' => TmuxPostProcessMode::class,
            'post_amazon_mode' => TmuxPostProcessMode::class,
            'post_non_mode' => TmuxPostProcessMode::class,
            'binaries_enabled' => 'boolean',
            'console_enabled' => 'boolean',
            'fix_names_enabled' => 'boolean',
            'htop_enabled' => 'boolean',
            'mytop_enabled' => 'boolean',
            'nmon_enabled' => 'boolean',
            'progressive_backfill' => 'boolean',
            'redis_enabled' => 'boolean',
            'releases_enabled' => 'boolean',
            'run_irc_scraper' => 'boolean',
            'tcp_track_enabled' => 'boolean',
            'vnstat_enabled' => 'boolean',
            'bwmng_enabled' => 'boolean',
            'write_logs' => 'boolean',
        ];
    }

    /** @return HasMany<TmuxCleanupRule, $this> */
    public function cleanupRules(): HasMany
    {
        return $this->hasMany(TmuxCleanupRule::class);
    }

    /** @return HasMany<TmuxColorExclusion, $this> */
    public function colorExclusions(): HasMany
    {
        return $this->hasMany(TmuxColorExclusion::class);
    }

    public const string FFI_CDEF = 'ssize_t nntmux_w346_configuration_status(uint8_t operation, const int64_t *input, size_t input_count, int64_t *output, size_t output_capacity);';

    // These small computations retain PHP until caller-inclusive benchmarks qualify Rust.
    private const bool RUST_FFI_DEFAULT = false;

    private static ?bool $useRustFfi = null;

    public static function setUseRustFfi(?bool $use = true): void
    {
        self::$useRustFfi = $use;
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi ?? self::RUST_FFI_DEFAULT;
    }

    /**
     * @param  list<int>  $input
     * @return list<int>|null
     */
    private static function rustValues(int $operation, array $input, int $count): ?array
    {
        try {
            $ffi = RustPipelineLibrary::resolveDomain('w346', self::FFI_CDEF);
            if ($ffi === null) {
                return null;
            }
            $length = count($input);
            $source = $ffi->new("int64_t[{$length}]");
            $output = $ffi->new("int64_t[{$count}]");
            foreach ($input as $index => $value) {
                $source[$index] = $value;
            }
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_w346_configuration_status($operation, $source, $length, $output, $count);
            if ($written !== $count) {
                return null;
            }
            $values = [];
            for ($index = 0; $index < $count; $index++) {
                $values[] = (int) $output[$index];
            }

            return $values;
        } catch (Throwable) {
            return null;
        }
    }

    /** @return array{sequential_mode: int, backfill_mode: int, post_mode: int, post_amazon_mode: int, post_non_mode: int} */
    public static function normalizeModes(int $sequential, int $backfill, int $post, int $amazon, int $non): array
    {
        if (self::usesRustFfi() && ($values = self::rustValues(2, [$sequential, $backfill, $post, $amazon, $non], 5)) !== null) {
            return ['sequential_mode' => $values[0], 'backfill_mode' => $values[1], 'post_mode' => $values[2], 'post_amazon_mode' => $values[3], 'post_non_mode' => $values[4]];
        }

        return self::normalizeModesPhp($sequential, $backfill, $post, $amazon, $non);
    }

    /** @return array{sequential_mode: int, backfill_mode: int, post_mode: int, post_amazon_mode: int, post_non_mode: int} */
    public static function normalizeModesPhp(int $sequential, int $backfill, int $post, int $amazon, int $non): array
    {
        return [
            'sequential_mode' => TmuxSequentialMode::tryFrom($sequential)->value ?? TmuxSequentialMode::Full->value,
            'backfill_mode' => TmuxBackfillMode::tryFrom($backfill)->value ?? TmuxBackfillMode::Disabled->value,
            'post_mode' => TmuxPostProcessMode::tryFrom($post)->value ?? TmuxPostProcessMode::Disabled->value,
            'post_amazon_mode' => TmuxPostProcessMode::tryFrom($amazon)->value ?? TmuxPostProcessMode::Disabled->value,
            'post_non_mode' => TmuxPostProcessMode::tryFrom($non)->value ?? TmuxPostProcessMode::Disabled->value,
        ];
    }
}
