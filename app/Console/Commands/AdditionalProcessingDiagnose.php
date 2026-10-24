<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\AdditionalProcessing\AdditionalProcessingDiagnostics;
use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;
use Throwable;

#[AsCommand(name: 'nntmux:additional-diagnose')]
class AdditionalProcessingDiagnose extends Command
{
    private const FFI_CDEF = <<<'CDEF'
ssize_t nntmux_additional_diagnose_backlog_rows(
    int64_t total,
    int64_t available,
    int64_t active_claims,
    int64_t stale_claims,
    uint8_t *out_buf,
    size_t out_cap,
    int64_t *out_lens
);
ssize_t nntmux_additional_diagnose_preflight_rows(
    const char *driver_ptr,
    size_t driver_len,
    uint8_t has_index,
    const char *index_ptr,
    size_t index_len,
    const char *path_ptr,
    size_t path_len,
    uint8_t writable,
    uint8_t *out_buf,
    size_t out_cap,
    int64_t *out_lens
);
ssize_t nntmux_additional_diagnose_format_warning(
    const char *code_ptr,
    size_t code_len,
    const char *message_ptr,
    size_t message_len,
    uint8_t *out_buf,
    size_t out_cap
);
CDEF;

    private const ROW_SEGMENTS = 8;

    /**
     * Rust FFI stays opt-in: these table projections are too small for FFI to beat PHP by 1.5x.
     */
    private static bool $useRustFfi = false;

    private static FFI|false|null $ffi = null;

    protected $signature = 'nntmux:additional-diagnose
                                {--json : Output machine-readable JSON}';

    protected $description = 'Inspect additional post-processing backlog, claims, capacity, indexes, and temp-path health';

    public function __construct(private readonly AdditionalProcessingDiagnostics $diagnostics)
    {
        parent::__construct();
    }

    public static function enableRustFfi(): void
    {
        self::$useRustFfi = true;
    }

    public static function disableRustFfi(): void
    {
        self::$useRustFfi = false;
    }

    public static function resetRustFfi(): void
    {
        self::$useRustFfi = false;
        self::$ffi = null;
    }

    public static function setUseRustFfi(?bool $useRustFfi): void
    {
        self::$useRustFfi = (bool) $useRustFfi;
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi;
    }

    public static function setFfiForTesting(FFI|false|null $ffi = null): void
    {
        self::$ffi = $ffi;
    }

    public function handle(): int
    {
        $report = $this->diagnostics->inspect();

        if ($this->option('json')) {
            $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $this->table(['Backlog', 'Count'], self::projectBacklogRows($report['backlog']));
        $this->table(['Capacity', 'Value'], [
            ['Pipeline', (string) $report['settings']['pipeline']],
            ['Threads', (string) $report['capacity']['threads']],
            ['Releases per batch', (string) $report['capacity']['releases_per_batch']],
            ['Maximum in flight', (string) $report['capacity']['max_in_flight']],
            ['Claim TTL', $report['claims']['ttl_seconds'].' seconds'],
            ['Child timeout', $report['settings']['multiprocessing_child_timeout'].' seconds'],
        ]);
        $this->table(['Preflight', 'Value'], self::projectPreflightRows($report));

        if ($report['warnings'] === []) {
            $this->info('No additional-processing diagnostic warnings were found.');

            return self::SUCCESS;
        }

        $this->newLine();
        foreach ($report['warnings'] as $warning) {
            $this->warn(self::formatWarningLine((string) $warning['code'], (string) $warning['message']));
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $backlog
     * @return list<array{0: string, 1: string}>
     */
    public static function projectBacklogRows(array $backlog): array
    {
        if (self::$useRustFfi) {
            return self::projectBacklogRowsNative($backlog);
        }

        return self::projectBacklogRowsPhp($backlog);
    }

    /**
     * @param  array<string, mixed>  $backlog
     * @return list<array{0: string, 1: string}>
     */
    public static function projectBacklogRowsPhp(array $backlog): array
    {
        return [
            ['Total', (string) $backlog['total']],
            ['Available', (string) $backlog['available']],
            ['Active claims', (string) $backlog['active_claims']],
            ['Stale claims', (string) $backlog['stale_claims']],
        ];
    }

    /**
     * @param  array<string, mixed>  $backlog
     * @return list<array{0: string, 1: string}>
     */
    public static function projectBacklogRowsNative(array $backlog): array
    {
        $total = $backlog['total'] ?? null;
        $available = $backlog['available'] ?? null;
        $activeClaims = $backlog['active_claims'] ?? null;
        $staleClaims = $backlog['stale_claims'] ?? null;
        if (! is_int($total) || ! is_int($available) || ! is_int($activeClaims) || ! is_int($staleClaims)) {
            return self::projectBacklogRowsPhp($backlog);
        }

        $ffi = self::additionalFfi();
        if (! $ffi instanceof FFI) {
            return self::projectBacklogRowsPhp($backlog);
        }

        try {
            $cap = 256;
            $outBuf = $ffi->new("uint8_t[{$cap}]");
            $outLens = $ffi->new('int64_t['.self::ROW_SEGMENTS.']');

            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_additional_diagnose_backlog_rows(
                $total,
                $available,
                $activeClaims,
                $staleClaims,
                $outBuf,
                $cap,
                $outLens
            );
            $rows = is_int($written) ? self::decodeRows($outBuf, $outLens, $written) : null;

            return $rows ?? self::projectBacklogRowsPhp($backlog);
        } catch (Throwable) {
            return self::projectBacklogRowsPhp($backlog);
        }
    }

    /**
     * @param  array<string, mixed>  $preflight  Diagnostics report with `database_driver`, `indexes`, and `temp_path`.
     * @return list<array{0: string, 1: string}>
     */
    public static function projectPreflightRows(array $preflight): array
    {
        if (self::$useRustFfi) {
            return self::projectPreflightRowsNative($preflight);
        }

        return self::projectPreflightRowsPhp($preflight);
    }

    /**
     * @param  array<string, mixed>  $preflight
     * @return list<array{0: string, 1: string}>
     */
    public static function projectPreflightRowsPhp(array $preflight): array
    {
        return [
            ['Database driver', (string) $preflight['database_driver']],
            ['Claim queue index', (string) ($preflight['indexes']['claim_queue_index'] ?? 'missing')],
            ['Temp path', (string) $preflight['temp_path']['path']],
            ['Temp path writable', $preflight['temp_path']['writable'] ? 'yes' : 'no'],
        ];
    }

    /**
     * @param  array<string, mixed>  $preflight
     * @return list<array{0: string, 1: string}>
     */
    public static function projectPreflightRowsNative(array $preflight): array
    {
        $driver = $preflight['database_driver'] ?? null;
        $index = $preflight['indexes']['claim_queue_index'] ?? null;
        $path = $preflight['temp_path']['path'] ?? null;
        $writable = $preflight['temp_path']['writable'] ?? null;
        if (! is_string($driver) || ($index !== null && ! is_string($index)) || ! is_string($path) || ! is_bool($writable)) {
            return self::projectPreflightRowsPhp($preflight);
        }

        $ffi = self::additionalFfi();
        if (! $ffi instanceof FFI) {
            return self::projectPreflightRowsPhp($preflight);
        }

        try {
            $indexStr = $index ?? '';
            $cap = strlen($driver) + strlen($indexStr) + strlen($path) + 128;
            $outBuf = $ffi->new("uint8_t[{$cap}]");
            $outLens = $ffi->new('int64_t['.self::ROW_SEGMENTS.']');

            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_additional_diagnose_preflight_rows(
                $driver,
                strlen($driver),
                $index !== null ? 1 : 0,
                $indexStr,
                strlen($indexStr),
                $path,
                strlen($path),
                $writable ? 1 : 0,
                $outBuf,
                $cap,
                $outLens
            );
            $rows = is_int($written) ? self::decodeRows($outBuf, $outLens, $written) : null;

            return $rows ?? self::projectPreflightRowsPhp($preflight);
        } catch (Throwable) {
            return self::projectPreflightRowsPhp($preflight);
        }
    }

    public static function formatWarningLine(string $code, string $message): string
    {
        if (self::$useRustFfi) {
            return self::formatWarningLineNative($code, $message);
        }

        return self::formatWarningLinePhp($code, $message);
    }

    public static function formatWarningLinePhp(string $code, string $message): string
    {
        return '['.$code.'] '.$message;
    }

    public static function formatWarningLineNative(string $code, string $message): string
    {
        $ffi = self::additionalFfi();
        if (! $ffi instanceof FFI) {
            return self::formatWarningLinePhp($code, $message);
        }

        try {
            $cap = strlen($code) + strlen($message) + 16;
            $outBuf = $ffi->new("uint8_t[{$cap}]");

            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_additional_diagnose_format_warning(
                $code,
                strlen($code),
                $message,
                strlen($message),
                $outBuf,
                $cap
            );
            if (! is_int($written) || $written <= 0) {
                return self::formatWarningLinePhp($code, $message);
            }

            return FFI::string($outBuf, $written);
        } catch (Throwable) {
            return self::formatWarningLinePhp($code, $message);
        }
    }

    /**
     * Rebuilds label/value rows from length-prefixed segments (segments may contain NUL bytes).
     *
     * @return list<array{0: string, 1: string}>|null
     */
    private static function decodeRows(CData $outBuf, CData $outLens, int $written): ?array
    {
        if ($written <= 0) {
            return null;
        }

        $bytes = FFI::string($outBuf, $written);
        $segments = [];
        $offset = 0;
        for ($i = 0; $i < self::ROW_SEGMENTS; $i++) {
            $length = $outLens[$i];
            if (! is_int($length) || $length < 0 || $offset + $length > $written) {
                return null;
            }
            $segments[] = substr($bytes, $offset, $length);
            $offset += $length;
        }
        if ($offset !== $written) {
            return null;
        }

        $rows = [];
        for ($i = 0; $i < self::ROW_SEGMENTS; $i += 2) {
            $rows[] = [$segments[$i], $segments[$i + 1]];
        }

        return $rows;
    }

    private static function additionalFfi(): ?FFI
    {
        if (self::$ffi === false) {
            return null;
        }

        if (self::$ffi instanceof FFI) {
            return self::$ffi;
        }

        $ffi = RustPipelineLibrary::resolveDomain('additional', self::FFI_CDEF);
        self::$ffi = $ffi ?? false;

        return $ffi;
    }
}
