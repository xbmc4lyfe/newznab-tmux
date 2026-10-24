<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\PurgeDeletedAccounts;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Console\Command;
use Throwable;

class NntmuxPurgeDeletedAccounts extends Command
{
    private const FFI_CDEF = <<<'CDEF'
int32_t nntmux_w32_table_account_gdpr_cleanup_commands(
    const char *input,
    size_t len,
    uint8_t *out,
    size_t out_cap
);
CDEF;

    /** Rust stays opt-in: the JSON FFI round trip is slower than these PHP helpers (< 1.5x). */
    private const bool RUST_FFI_DEFAULT = false;

    private static ?bool $useRustFfi = null;

    private static FFI|false|null $ffi = null;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'nntmux:purge-deleted-accounts';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Permanently remove accounts that were soft deleted 6 months ago';

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi ?? self::RUST_FFI_DEFAULT;
    }

    public static function setUseRustFfi(?bool $use): void
    {
        self::$useRustFfi = $use;
    }

    public static function resetRustFfi(): void
    {
        self::$useRustFfi = null;
        self::$ffi = null;
    }

    public static function setFfiForTesting(FFI|false|null $ffi = null): void
    {
        self::$ffi = $ffi;
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $summary = self::normalizePurgeSummary(false, (int) config('nntmux.purge_inactive_users_days'), 0);
        $this->info($summary['start_message']);

        PurgeDeletedAccounts::dispatch();

        $this->info($summary['finish_message']);

        return Command::SUCCESS;
    }

    /**
     * Console lines for a purge run; negative retention days and counts are clamped to zero.
     *
     * @return array{dry_run: bool, retention_days: int, count: int, start_message: string, finish_message: string}
     */
    public static function normalizePurgeSummary(bool $dryRun, int $retentionDays, int $count): array
    {
        return self::usesRustFfi()
            ? self::normalizePurgeSummaryNative($dryRun, $retentionDays, $count)
            : self::normalizePurgeSummaryPhp($dryRun, $retentionDays, $count);
    }

    /**
     * @return array{dry_run: bool, retention_days: int, count: int, start_message: string, finish_message: string}
     */
    public static function normalizePurgeSummaryPhp(bool $dryRun, int $retentionDays, int $count): array
    {
        $retentionDays = max(0, $retentionDays);
        $count = max(0, $count);

        return [
            'dry_run' => $dryRun,
            'retention_days' => $retentionDays,
            'count' => $count,
            'start_message' => 'Starting to purge accounts that were soft deleted 6 months ago...',
            'finish_message' => $dryRun
                ? "Dry run: {$count} account(s) soft deleted more than {$retentionDays} day(s) ago would be purged"
                : 'Job dispatched to purge deleted accounts',
        ];
    }

    /**
     * @return array{dry_run: bool, retention_days: int, count: int, start_message: string, finish_message: string}
     */
    public static function normalizePurgeSummaryNative(bool $dryRun, int $retentionDays, int $count): array
    {
        if (self::callNative(['purge_deleted.normalize_summary', $dryRun, $retentionDays, $count], $result)
            && is_array($result)
            && array_keys($result) === ['dry_run', 'retention_days', 'count', 'start_message', 'finish_message']
            && is_bool($result['dry_run'])
            && is_int($result['retention_days'])
            && is_int($result['count'])
            && is_string($result['start_message'])
            && is_string($result['finish_message'])) {
            /** @var array{dry_run: bool, retention_days: int, count: int, start_message: string, finish_message: string} $result */
            return $result;
        }

        return self::normalizePurgeSummaryPhp($dryRun, $retentionDays, $count);
    }

    /**
     * Evaluates `[operation, ...arguments]` in Rust; false means the caller keeps its PHP result.
     *
     * @param  list<mixed>  $request
     *
     * @param-out mixed $result
     */
    private static function callNative(array $request, mixed &$result = null): bool
    {
        $ffi = self::nativeFfi();
        if (! $ffi instanceof FFI) {
            return false;
        }

        try {
            $json = json_encode($request, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
            $capacity = max(256, strlen($json) * 2 + 256);
            for ($attempt = 0; $attempt < 2; $attempt++) {
                $buffer = $ffi->new("uint8_t[{$capacity}]");
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_w32_table_account_gdpr_cleanup_commands($json, strlen($json), $buffer, $capacity);
                if (! is_int($written) || $written === -1 || $written === 0) {
                    return false;
                }
                if ($written < 0) {
                    $capacity = -$written;

                    continue;
                }
                $decoded = json_decode(FFI::string($buffer, $written), true, 8, JSON_THROW_ON_ERROR);
                if (! is_array($decoded) || count($decoded) !== 1 || ! array_key_exists(0, $decoded)) {
                    return false;
                }
                $result = $decoded[0];

                return true;
            }
        } catch (Throwable) {
            return false;
        }

        return false;
    }

    private static function nativeFfi(): ?FFI
    {
        if (self::$ffi === false) {
            return null;
        }
        if (self::$ffi instanceof FFI) {
            return self::$ffi;
        }
        $ffi = RustPipelineLibrary::resolveDomain('cleaning', self::FFI_CDEF);
        self::$ffi = $ffi ?? false;

        return $ffi;
    }
}
