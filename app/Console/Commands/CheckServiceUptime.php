<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\SiteStatusService;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Console\Command;
use Throwable;

class CheckServiceUptime extends Command
{
    private const FFI_CDEF = <<<'CDEF'
ssize_t nntmux_w336_locks_uptime(uint8_t operation, const char *input, size_t input_len, int64_t first, int64_t second, double value, uint8_t flag, uint8_t *out, size_t out_cap);
CDEF;

    // Single-call FFI must qualify on every common mean and uncommon p99 workload.
    private const bool RUST_FFI_DEFAULT = false;

    private static ?bool $useRustFfi = null;

    private static FFI|false|null $ffi = null;

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi ?? self::RUST_FFI_DEFAULT;
    }

    public static function setUseRustFfi(?bool $use): void
    {
        self::$useRustFfi = $use;
    }

    /**
     * Length-prefixed fields preserve arbitrary PHP string bytes, including NULs.
     *
     * @param  list<string>  $fields
     * @return list<string>|null
     */
    private static function additionalResult(int $operation, array $fields = [], int $first = 0, int $second = 0, float $value = 0.0, bool $flag = false): ?array
    {
        try {
            if (self::$ffi === null) {
                self::$ffi = RustPipelineLibrary::resolveDomain('additional', self::FFI_CDEF) ?? false;
            }
            $ffi = self::$ffi;
            if (! $ffi instanceof FFI) {
                return null;
            }
            $input = '';
            foreach ($fields as $field) {
                if (strlen($field) > 16_777_216) {
                    return null;
                }
                $input .= pack('N', strlen($field)).$field;
            }
            $capacity = strlen($input) + 512;
            $out = $ffi->new('uint8_t['.$capacity.']');
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_w336_locks_uptime($operation, $input, strlen($input), $first, $second, $value, (int) $flag, $out, $capacity);
            if ($written <= 0 || $written > $capacity) {
                return null;
            }
            $bytes = FFI::string($out, $written);
            $result = [];
            $offset = 0;
            while ($offset < $written) {
                if ($written - $offset < 4) {
                    return null;
                }
                $header = unpack('Nlength', substr($bytes, $offset, 4));
                if ($header === false || ! is_int($header['length'])) {
                    return null;
                }
                $length = $header['length'];
                $offset += 4;
                if ($length > $written - $offset) {
                    return null;
                }
                $result[] = substr($bytes, $offset, $length);
                $offset += $length;
            }

            return $result;
        } catch (Throwable) {
            return null;
        }
    }

    /** @return array{string, string, string, string} */
    public static function formatServiceUptimeRow(string $name, string $statusLabel, float $uptimePercentage, ?string $checkedDiff): array
    {
        return self::usesRustFfi() ? self::formatServiceUptimeRowNative($name, $statusLabel, $uptimePercentage, $checkedDiff) : self::formatServiceUptimeRowPhp($name, $statusLabel, $uptimePercentage, $checkedDiff);
    }

    /** @return array{string, string, string, string} */
    public static function formatServiceUptimeRowPhp(string $name, string $statusLabel, float $uptimePercentage, ?string $checkedDiff): array
    {
        return [$name, $statusLabel, number_format($uptimePercentage, 2).'%', $checkedDiff ?? 'never'];
    }

    /** @return array{string, string, string, string} */
    public static function formatServiceUptimeRowNative(string $name, string $statusLabel, float $uptimePercentage, ?string $checkedDiff): array
    {
        $result = self::additionalResult(6, [$name, $statusLabel, $checkedDiff ?? ''], value: $uptimePercentage, flag: $checkedDiff !== null);

        return $result !== null && count($result) === 4 ? [$result[0], $result[1], $result[2], $result[3]] : self::formatServiceUptimeRowPhp($name, $statusLabel, $uptimePercentage, $checkedDiff);
    }

    protected $signature = 'nntmux:check-service-uptime
                            {--days=30 : Rolling window in days for uptime calculation}';

    protected $description = 'Recalculate uptime percentages for all enabled services based on incident history';

    public function handle(SiteStatusService $statusService): int
    {
        $days = (int) $this->option('days');

        $this->components->info("Recalculating service uptime over a {$days}-day window…");

        $statusService->refreshAllServiceUptime($days);

        $services = $statusService->getEnabledServices();
        $rows = $services->map(fn ($s) => self::formatServiceUptimeRow(
            $s->name,
            $s->status->label(),
            (float) $s->uptime_percentage,
            $s->last_checked_at?->diffForHumans(),
        ))->all();

        $this->table(['Service', 'Status', 'Uptime', 'Checked'], $rows);

        return self::SUCCESS;
    }
}
