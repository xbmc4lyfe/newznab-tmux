<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\UserActivityStat;
use App\Support\RustPipelineLibrary;
use Carbon\Carbon;
use FFI;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

class BackfillUserActivityStats extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'nntmux:backfill-user-activity-stats
                            {--type=daily : Type of stats to backfill (daily or hourly)}
                            {--days=30 : Number of days to backfill (for daily stats)}
                            {--hours=24 : Number of hours to backfill (for hourly stats)}
                            {--force : Force backfill even if data already exists}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Backfill hourly activity from raw logs or daily activity from retained hourly aggregates';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $type = $this->option('type') ?? '';
        $force = $this->option('force');

        if (! self::isValidStatType($type)) {
            $this->error("Invalid type '{$type}'. Must be 'daily' or 'hourly'.");

            return Command::FAILURE;
        }

        if ($type === 'daily') {
            return $this->backfillDaily($force);
        } else {
            return $this->backfillHourly($force);
        }
    }

    /**
     * Backfill daily stats
     */
    protected function backfillDaily(bool $force): int
    {
        $days = (int) $this->option('days');

        $this->info("Backfilling daily user activity stats for the last {$days} days...");

        $progressBar = $this->output->createProgressBar($days);

        $statsCollected = 0;
        $statsSkipped = 0;

        for ($i = $days - 1; $i >= 0; $i--) {
            $date = Carbon::now()->subDays($i)->format('Y-m-d');

            // Check if stats already exist for this date
            if (! $force && UserActivityStat::where('stat_date', $date)->exists()) {
                $statsSkipped++;
                $progressBar->advance();

                continue;
            }

            UserActivityStat::collectDailyStats($date);

            $statsCollected++;
            $progressBar->advance();
        }

        $progressBar->finish();
        $this->newLine(2);

        $this->info('Daily backfill complete!');
        // Keep the daily/hourly heading while sharing the completion count lines.
        foreach (array_slice(self::formatCompletionLines($statsCollected, $statsSkipped), 1) as $line) {
            $this->info($line);
        }

        return Command::SUCCESS;
    }

    /**
     * Backfill hourly stats
     */
    protected function backfillHourly(bool $force): int
    {
        $hours = (int) $this->option('hours');

        $this->info("Backfilling hourly user activity stats for the last {$hours} hours...");

        $progressBar = $this->output->createProgressBar($hours);

        $statsCollected = 0;
        $statsSkipped = 0;

        for ($i = $hours - 1; $i >= 0; $i--) {
            $hour = Carbon::now()->subHours($i)->startOfHour()->format('Y-m-d H:00:00');

            // Check if stats already exist for this hour
            if (! $force && DB::table('user_activity_stats_hourly')->where('stat_hour', $hour)->exists()) {
                $statsSkipped++;
                $progressBar->advance();

                continue;
            }

            UserActivityStat::collectHourlyStats($hour);

            $statsCollected++;
            $progressBar->advance();
        }

        $progressBar->finish();
        $this->newLine(2);

        $this->info('Hourly backfill complete!');
        // Keep the daily/hourly heading while sharing the completion count lines.
        foreach (array_slice(self::formatCompletionLines($statsCollected, $statsSkipped), 1) as $line) {
            $this->info($line);
        }

        return Command::SUCCESS;
    }

    // FFI serialization/allocation does not qualify for the 1.5x default-on gate.
    private static bool $useRustFfi = false;

    private static FFI|false|null $ffi = null;

    public const string FFI_CDEF = 'ptrdiff_t nntmux_activity_captcha_cloudflare_expiry(unsigned int mode, const char *input, size_t input_len, char *out, size_t cap);';

    public static function setUseRustFfi(?bool $enabled): void
    {
        self::$useRustFfi = $enabled ?? false;
    }

    public static function useRustFfi(): bool
    {
        return self::$useRustFfi;
    }

    public static function usesRustFfi(): bool
    {
        return self::useRustFfi();
    }

    private static function ffi(): ?FFI
    {
        if (self::$ffi === null) {
            $path = getenv('NNTMUX_PIPELINE_FFI_LIB');
            self::$ffi = RustPipelineLibrary::resolveDomain(
                'activity-captcha-cloudflare-expiry', self::FFI_CDEF,
                is_string($path) && $path !== '' ? $path : null,
            ) ?? false;
        }

        return self::$ffi instanceof FFI ? self::$ffi : null;
    }

    private static function callFfi(FFI $ffi, string $symbol, mixed ...$args): mixed
    {
        return $ffi->$symbol(...$args);
    }

    /**
     * Length-prefixed binary packets preserve PHP byte strings, including NULs.
     *
     * @param  list<string>  $items
     * @return list<string>|null
     */
    private static function nativePacket(int $mode, array $items): ?array
    {
        $ffi = self::ffi();
        if ($ffi === null || count($items) > 100000) {
            return null;
        }

        try {
            $input = pack('V', count($items));
            foreach ($items as $item) {
                if (strlen($item) > 16777216) {
                    return null;
                }
                $input .= pack('V', strlen($item)).$item;
                if (strlen($input) > 16777216) {
                    return null;
                }
            }
            $cap = strlen($input) + 256;
            $out = $ffi->new("char[{$cap}]");
            $written = self::callFfi($ffi, 'nntmux_activity_captcha_cloudflare_expiry', $mode, $input, strlen($input), $out, $cap);
            if (is_int($written) && $written < 0 && $written >= -16777216) {
                $cap = -$written;
                $out = $ffi->new("char[{$cap}]");
                $written = self::callFfi($ffi, 'nntmux_activity_captcha_cloudflare_expiry', $mode, $input, strlen($input), $out, $cap);
            }
            if (! is_int($written) || $written < 4 || $written > $cap) {
                return null;
            }
            $packet = FFI::string($out, $written);
            $count = unpack('Vcount', $packet)['count'];
            if ($count > intdiv($written - 4, 4)) {
                return null;
            }
            $offset = 4;
            $result = [];
            for ($i = 0; $i < $count; $i++) {
                if ($offset + 4 > $written) {
                    return null;
                }
                $length = unpack('Vlength', $packet, $offset)['length'];
                $offset += 4;
                if ($length > $written - $offset) {
                    return null;
                }
                $result[] = substr($packet, $offset, $length);
                $offset += $length;
            }

            return $offset === $written ? $result : null;
        } catch (Throwable) {
            return null;
        }
    }

    public static function isValidStatType(string $type): bool
    {
        return self::useRustFfi() ? self::isValidStatTypeNative($type) : self::isValidStatTypePhp($type);
    }

    public static function isValidStatTypePhp(string $type): bool
    {
        return in_array($type, ['daily', 'hourly'], true);
    }

    public static function isValidStatTypeNative(string $type): bool
    {
        return match (self::nativePacket(1, [$type])[0] ?? null) {
            '1' => true,
            '0' => false,
            default => self::isValidStatTypePhp($type),
        };
    }

    /** @return list<string> */
    public static function formatCompletionLines(int $collected, int $skipped): array
    {
        return self::useRustFfi() ? self::formatCompletionLinesNative($collected, $skipped) : self::formatCompletionLinesPhp($collected, $skipped);
    }

    /** @return list<string> */
    public static function formatCompletionLinesPhp(int $collected, int $skipped): array
    {
        $lines = ['Backfill completed!', "Stats collected: {$collected}"];
        if ($skipped > 0) {
            $lines[] = "Stats skipped (already existed): {$skipped}";
        }

        return $lines;
    }

    /** @return list<string> */
    public static function formatCompletionLinesNative(int $collected, int $skipped): array
    {
        return self::nativePacket(2, [(string) $collected, (string) $skipped]) ?? self::formatCompletionLinesPhp($collected, $skipped);
    }
}
