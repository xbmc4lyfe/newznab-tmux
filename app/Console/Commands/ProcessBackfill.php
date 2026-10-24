<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\ForkingService;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProcessBackfill extends Command
{
    public const string FFI_CDEF = <<<'CDEF'
        int32_t nntmux_cat_gmr_cmd_backfill_options(uint8_t kind, int64_t int_value, double float_value, const char *text_ptr, size_t text_len, int64_t *out_value);
        CDEF;

    private static bool $useRustFfi = false;

    private static FFI|false|null $ffi = null;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'multiprocessing:backfill
                            {limit? : Limit article count (optional)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Backfill all backfill-enabled groups using multiprocessing';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $options = self::normalizeBackfillOptions($this->argument('limit'));

        try {
            (new ForkingService)->backfill($options); // @phpstan-ignore argument.type

            return self::SUCCESS;
        } catch (\Exception $e) {
            Log::error($e->getTraceAsString());
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }

    /**
     * Overrides the PHP default for this command's helpers; `null` restores the PHP default.
     */
    public static function setUseRustFfi(?bool $useRustFfi): void
    {
        self::$useRustFfi = $useRustFfi ?? false;
    }

    public static function useRustFfi(bool $enabled = true): void
    {
        self::$useRustFfi = $enabled;
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi;
    }

    public static function setFfiForTesting(FFI|false|null $ffi): void
    {
        self::$ffi = $ffi;
    }

    /**
     * `[0 => (int) $limit]` for a positive numeric limit, otherwise `[0 => false]`.
     *
     * @return array{0: int|false}
     */
    public static function normalizeBackfillOptions(mixed $limit): array
    {
        return self::$useRustFfi ? self::normalizeBackfillOptionsNative($limit) : self::normalizeBackfillOptionsPhp($limit);
    }

    /**
     * @return array{0: int|false}
     */
    public static function normalizeBackfillOptionsPhp(mixed $limit): array
    {
        return $limit !== null && is_numeric($limit) && $limit > 0 ? [0 => (int) $limit] : [0 => false];
    }

    /**
     * @return array{0: int|false}
     */
    public static function normalizeBackfillOptionsNative(mixed $limit): array
    {
        $ffi = self::ffi();
        $arguments = match (true) {
            $limit === null => [0, 0, 0.0, '', 0],
            \is_bool($limit) => [1, $limit ? 1 : 0, 0.0, '', 0],
            \is_int($limit) => [2, $limit, 0.0, '', 0],
            \is_float($limit) => [3, 0, $limit, '', 0],
            \is_string($limit) => [4, 0, 0.0, $limit, \strlen($limit)],
            default => null,
        };
        if ($ffi === null || PHP_INT_SIZE !== 8 || $arguments === null) {
            return self::normalizeBackfillOptionsPhp($limit);
        }

        try {
            $value = $ffi->new('int64_t[1]');
            // @phpstan-ignore method.notFound
            $rc = $ffi->nntmux_cat_gmr_cmd_backfill_options(...[...$arguments, $value]);

            return match ($rc) {
                1 => [0 => (int) $value[0]],
                0 => [0 => false],
                default => self::normalizeBackfillOptionsPhp($limit),
            };
        } catch (Throwable) {
            return self::normalizeBackfillOptionsPhp($limit);
        }
    }

    private static function ffi(): ?FFI
    {
        if (self::$ffi === false) {
            return null;
        }

        if (self::$ffi instanceof FFI) {
            return self::$ffi;
        }

        try {
            $ffi = RustPipelineLibrary::resolveDomain('w31-3-process-backfill', self::FFI_CDEF, getenv('NNTMUX_PIPELINE_FFI_LIB') ?: null);
        } catch (Throwable) {
            $ffi = null;
        }
        self::$ffi = $ffi ?? false;

        return $ffi;
    }
}
