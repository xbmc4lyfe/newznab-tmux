<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\AdminDashboardSnapshotService;
use App\Services\Search\DTO\SearchCursor;
use Illuminate\Console\Command;

class WarmAdminDashboard extends Command
{
    protected $signature = 'admin:warm-dashboard';

    protected $description = 'Rebuild and re-cache the admin dashboard snapshot (Cache::flexible payload)';

    /**
     * Rust stays opt-in: the FFI round trip is slower than the PHP helper (< 1.5x); see
     * tests/Unit/RustSearchCursorConfigStatsParityTest.php.
     */
    private const bool RUST_FFI_DEFAULT = false;

    private static ?bool $useRustFfi = null;

    public function handle(AdminDashboardSnapshotService $snapshot): int
    {
        $start = microtime(true);
        $snapshot->warm();

        $this->info(self::formatWarmSummary($start, microtime(true))['message']);

        return self::SUCCESS;
    }

    public static function setUseRustFfi(?bool $use): void
    {
        self::$useRustFfi = $use;
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi ?? self::RUST_FFI_DEFAULT;
    }

    /**
     * Elapsed whole milliseconds between two microtime(true) readings, truncated toward zero.
     *
     * @return array{elapsed_ms: int, message: string}
     */
    public static function formatWarmSummary(float $start, float $end): array
    {
        return self::usesRustFfi() ? self::formatWarmSummaryNative($start, $end) : self::formatWarmSummaryPhp($start, $end);
    }

    /** @return array{elapsed_ms: int, message: string} */
    public static function formatWarmSummaryPhp(float $start, float $end): array
    {
        $elapsed = (int) (($end - $start) * 1000);

        return ['elapsed_ms' => $elapsed, 'message' => "Admin dashboard snapshot warmed in {$elapsed} ms."];
    }

    /**
     * Floats cross the FFI bit-exactly as little-endian doubles; a non-finite or out-of-range duration
     * stays in PHP, which owns its float-to-int conversion rules.
     *
     * @return array{elapsed_ms: int, message: string}
     */
    public static function formatWarmSummaryNative(float $start, float $end): array
    {
        $result = SearchCursor::evaluateNative(8, [pack('e', $start), pack('e', $end)]);
        if ($result !== null && \count($result) === 2 && preg_match('/^-?[0-9]+$/D', $result[0]) === 1) {
            $elapsed = (int) $result[0];
            if ((string) $elapsed === $result[0] && $result[1] === "Admin dashboard snapshot warmed in {$elapsed} ms.") {
                return ['elapsed_ms' => $elapsed, 'message' => $result[1]];
            }
        }

        return self::formatWarmSummaryPhp($start, $end);
    }
}
