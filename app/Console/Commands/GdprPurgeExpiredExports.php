<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Gdpr\GdprRetentionService;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Console\Command;
use Throwable;

class GdprPurgeExpiredExports extends Command
{
    public const string FFI_CDEF = <<<'C'
        ssize_t nntmux_w361_user_gdpr_auth(uint8_t operation, const char *input, size_t input_len, char *out_buf, size_t out_cap);
        C;

    private const array PURGE_STATUSES = ['idle', 'complete', 'partial', 'failed'];

    /**
     * PHP remains the default: the paired benchmark in
     * tests/Unit/RustUserGdprAuthEventsProviderParityTest.php is below 1.5x.
     */
    private const bool RUST_FFI_DEFAULT = false;

    private static ?bool $useRustFfi = null;

    private static FFI|false|null $ffi = null;

    protected $signature = 'gdpr:purge-expired-exports';

    protected $description = 'Purge expired GDPR export files while retaining the request audit record.';

    public function handle(GdprRetentionService $retentionService): int
    {
        $count = $retentionService->purgeExpiredExports();

        $this->info("Purged {$count} expired GDPR export file(s).");

        return self::SUCCESS;
    }

    public static function setUseRustFfi(?bool $enabled): void
    {
        self::$useRustFfi = $enabled;
        if ($enabled === null) {
            self::$ffi = null;
        }
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi ?? self::RUST_FFI_DEFAULT;
    }

    /**
     * Clamps purge counters so deleted + failed + skipped always equals the candidate count.
     *
     * @return array{retention_days: int, candidates: int, deleted: int, failed: int, skipped: int, status: string, has_failures: bool}
     */
    public static function summarizePurgePlan(int $retentionDays, int $candidateCount, int $deletedCount, int $failedCount): array
    {
        return (self::usesRustFfi() ? self::summarizePurgePlanNative($retentionDays, $candidateCount, $deletedCount, $failedCount) : null)
            ?? self::summarizePurgePlanPhp($retentionDays, $candidateCount, $deletedCount, $failedCount);
    }

    /**
     * @return array{retention_days: int, candidates: int, deleted: int, failed: int, skipped: int, status: string, has_failures: bool}
     */
    public static function summarizePurgePlanPhp(int $retentionDays, int $candidateCount, int $deletedCount, int $failedCount): array
    {
        $candidates = max(0, $candidateCount);
        $deleted = min($candidates, max(0, $deletedCount));
        $failed = min($candidates - $deleted, max(0, $failedCount));

        return [
            'retention_days' => max(0, $retentionDays),
            'candidates' => $candidates,
            'deleted' => $deleted,
            'failed' => $failed,
            'skipped' => $candidates - $deleted - $failed,
            'status' => match (true) {
                $candidates === 0 => 'idle',
                $deleted === $candidates => 'complete',
                $deleted > 0 => 'partial',
                default => 'failed',
            },
            'has_failures' => $failed > 0,
        ];
    }

    /**
     * @return array{retention_days: int, candidates: int, deleted: int, failed: int, skipped: int, status: string, has_failures: bool}|null
     */
    public static function summarizePurgePlanNative(int $retentionDays, int $candidateCount, int $deletedCount, int $failedCount): ?array
    {
        $bytes = self::rust(1, pack('q4', $retentionDays, $candidateCount, $deletedCount, $failedCount));
        if ($bytes === null || strlen($bytes) !== 56) {
            return null;
        }
        /** @var array{1: int, 2: int, 3: int, 4: int, 5: int, 6: int, 7: int} $fields */
        $fields = unpack('q7', $bytes);
        $status = self::PURGE_STATUSES[$fields[6]] ?? null;
        if ($status === null || ($fields[7] !== 0 && $fields[7] !== 1)) {
            return null;
        }

        return [
            'retention_days' => $fields[1],
            'candidates' => $fields[2],
            'deleted' => $fields[3],
            'failed' => $fields[4],
            'skipped' => $fields[5],
            'status' => $status,
            'has_failures' => $fields[7] === 1,
        ];
    }

    private static function rust(int $operation, string $input): ?string
    {
        try {
            self::$ffi ??= RustPipelineLibrary::resolveDomain('cleaning', self::FFI_CDEF) ?? false;
            if (self::$ffi === false) {
                return null;
            }
            $capacity = 0;
            $buffer = RustPipelineLibrary::obtainScratch(self::$ffi, strlen($input) + 128, $capacity);
            if ($buffer === null) {
                return null;
            }
            // @phpstan-ignore method.notFound
            $written = self::$ffi->nntmux_w361_user_gdpr_auth($operation, $input, strlen($input), $buffer, $capacity);
            if (! is_int($written) || $written < 0 || $written > $capacity) {
                return null;
            }

            return FFI::string($buffer, $written);
        } catch (Throwable) {
            return null;
        }
    }
}
