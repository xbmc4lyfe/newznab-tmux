<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\DownloadStat;
use App\Models\GrabStat;
use App\Models\ReleaseStat;
use App\Models\RoleStat;
use App\Models\SignupStat;
use App\Models\UserActivityStat;
use App\Services\Search\DTO\SearchCursor;
use Illuminate\Console\Command;

class CollectStats extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'nntmux:collect-stats';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Collects and stores various statistics about the site.';

    /**
     * Rust stays opt-in: the FFI round trip is slower than the PHP helper (< 1.5x); see
     * tests/Unit/RustSearchCursorConfigStatsParityTest.php.
     */
    private const bool RUST_FFI_DEFAULT = false;

    private static ?bool $useRustFfi = null;

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        $this->info('Collecting site stats...');
        $steps = [
            'Top grabbers' => GrabStat::insertTopGrabbers(...),
            'Top downloads' => DownloadStat::insertTopDownloads(...),
            'Recently added releases' => ReleaseStat::insertRecentlyAdded(...),
            'New users by month' => SignupStat::insertUsersByMonth(...),
            'Users by role' => RoleStat::insertUsersByRole(...),
            'User activity hourly stats' => UserActivityStat::collectHourlyStats(...),
            'User activity daily stats' => UserActivityStat::collectDailyStats(...),
        ];
        foreach ($steps as $label => $collect) {
            $start = hrtime(true);
            $collect();
            $this->info(self::formatStepMessage($label, intdiv(hrtime(true) - $start, 1_000_000)));
        }
        $this->info('Site stats collected.');
    }

    public static function setUseRustFfi(?bool $use): void
    {
        self::$useRustFfi = $use;
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi ?? self::RUST_FFI_DEFAULT;
    }

    /** "{label} collected in {ms} ms."; a negative duration reports 0 ms. */
    public static function formatStepMessage(string $label, int $elapsedMs): string
    {
        return self::usesRustFfi() ? self::formatStepMessageNative($label, $elapsedMs) : self::formatStepMessagePhp($label, $elapsedMs);
    }

    public static function formatStepMessagePhp(string $label, int $elapsedMs): string
    {
        return $label.' collected in '.max(0, $elapsedMs).' ms.';
    }

    public static function formatStepMessageNative(string $label, int $elapsedMs): string
    {
        $result = SearchCursor::evaluateNative(7, [$label, (string) $elapsedMs]);

        return $result !== null && \count($result) === 1 && str_starts_with($result[0], $label.' collected in ') && str_ends_with($result[0], ' ms.')
            ? $result[0]
            : self::formatStepMessagePhp($label, $elapsedMs);
    }
}
