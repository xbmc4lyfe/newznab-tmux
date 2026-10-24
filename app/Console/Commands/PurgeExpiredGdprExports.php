<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Gdpr\GdprRetentionService;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Console\Command;
use Throwable;

class PurgeExpiredGdprExports extends Command
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
    protected $signature = 'gdpr:purge-expired-exports';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Delete expired GDPR data export files and clear their stored paths to honour the retention policy.';

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

    public function handle(GdprRetentionService $retentionService): int
    {
        try {
            $purged = $retentionService->purgeExpiredExports();

            $summary = self::summarizeExportPurgeResult($purged);
            $this->info($summary['message']);

            return $summary['exit_code'];
        } catch (Throwable $e) {
            $summary = self::summarizeExportPurgeResult(0, $e->getMessage());
            $this->error($summary['message']);

            return $summary['exit_code'];
        }
    }

    /**
     * Console line and exit code for a purge run; a non-null error message marks a failure.
     *
     * @return array{success: bool, level: string, message: string, exit_code: int}
     */
    public static function summarizeExportPurgeResult(int $purgedCount, ?string $errorMessage = null): array
    {
        return self::usesRustFfi()
            ? self::summarizeExportPurgeResultNative($purgedCount, $errorMessage)
            : self::summarizeExportPurgeResultPhp($purgedCount, $errorMessage);
    }

    /**
     * @return array{success: bool, level: string, message: string, exit_code: int}
     */
    public static function summarizeExportPurgeResultPhp(int $purgedCount, ?string $errorMessage = null): array
    {
        if ($errorMessage !== null) {
            return [
                'success' => false,
                'level' => 'error',
                'message' => 'Failed to purge expired GDPR exports: '.$errorMessage,
                'exit_code' => self::FAILURE,
            ];
        }

        return [
            'success' => true,
            'level' => 'info',
            'message' => "Purged {$purgedCount} expired GDPR export file(s).",
            'exit_code' => self::SUCCESS,
        ];
    }

    /**
     * @return array{success: bool, level: string, message: string, exit_code: int}
     */
    public static function summarizeExportPurgeResultNative(int $purgedCount, ?string $errorMessage = null): array
    {
        if (self::callNative(['gdpr_exports.summarize_purge_result', $purgedCount, $errorMessage], $result)
            && is_array($result)
            && array_keys($result) === ['success', 'level', 'message', 'exit_code']
            && is_bool($result['success'])
            && is_string($result['level'])
            && is_string($result['message'])
            && is_int($result['exit_code'])) {
            /** @var array{success: bool, level: string, message: string, exit_code: int} $result */
            return $result;
        }

        return self::summarizeExportPurgeResultPhp($purgedCount, $errorMessage);
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
