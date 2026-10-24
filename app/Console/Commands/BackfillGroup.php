<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Backfill\BackfillService;
use App\Services\Configuration\ConfigurationProvider;
use App\Services\NNTP\NNTPService;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

class BackfillGroup extends Command
{
    public const string FFI_CDEF = <<<'CDEF'
        int32_t nntmux_cat_rgb_cmd_valid_backfill_type(int64_t backfill_type);
        int32_t nntmux_cat_rgb_cmd_resolve_quantity(uint8_t quantity_present, const char *quantity_ptr, size_t quantity_len, int64_t backfill_type, int64_t default_quantity, int64_t *out_value);
        ssize_t nntmux_cat_rgb_cmd_message(uint8_t kind, const char *text_ptr, size_t text_len, char *out_buf, size_t out_cap);
        CDEF;

    private static bool $useRustFfi = false;

    private static FFI|false|null $ffi = null;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'backfill:group
                            {group : The group name to backfill}
                            {type=1 : Backfill type (1=interval, 2=all)}
                            {quantity? : Number of articles to backfill}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Backfill a specific usenet group';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $group = $this->argument('group');
        $type = (int) $this->argument('type');
        $quantity = $this->argument('quantity');

        if (! self::isValidBackfillType($type)) {
            $this->error('Invalid backfill type. Must be 1 (interval) or 2 (all).');

            return self::FAILURE;
        }

        try {
            $nntp = $this->getNntp();

            if ($quantity === null) {
                $quantity = self::resolveQuantity(null, $type, app(ConfigurationProvider::class)->ingestion()->backfillQuantity);
            } elseif (\is_string($quantity) && is_numeric($quantity)) {
                // A non-numeric quantity stays a string so the service applies its default count.
                $quantity = self::resolveQuantity($quantity, $type, 0);
            }

            $this->info("Backfilling group: {$group}");
            (new BackfillService(nntp: $nntp))->backfillAllGroups($group, $quantity);

            return self::SUCCESS;
        } catch (Throwable $e) {
            Log::error($e->getTraceAsString());
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }

    /**
     * Get NNTP connection.
     */
    private function getNntp(): NNTPService
    {
        $nntp = new NNTPService;

        $connectResult = config('nntmux_nntp.use_alternate_nntp_server') === true
            ? $nntp->doConnect(false, true)
            : $nntp->doConnect();

        if ($connectResult !== true) {
            $errorMessage = 'Unable to connect to usenet.';
            if (NNTPService::isError($connectResult)) {
                $errorMessage .= ' Error: '.$connectResult->getMessage();
            }
            throw new \Exception($errorMessage);
        }

        return $nntp;
    }

    /**
     * Overrides the PHP default for this command's helpers; `null` restores the PHP default.
     */
    public static function setUseRustFfi(?bool $useRustFfi): void
    {
        self::$useRustFfi = $useRustFfi ?? false;
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
     * `1` (interval) and `2` (all) are the supported backfill types.
     */
    public static function isValidBackfillType(int $type): bool
    {
        return self::$useRustFfi ? self::isValidBackfillTypeNative($type) : self::isValidBackfillTypePhp($type);
    }

    public static function isValidBackfillTypePhp(int $type): bool
    {
        return \in_array($type, [1, 2], true);
    }

    public static function isValidBackfillTypeNative(int $type): bool
    {
        $ffi = self::ffi();
        if ($ffi === null || PHP_INT_SIZE !== 8) {
            return self::isValidBackfillTypePhp($type);
        }

        try {
            // @phpstan-ignore method.notFound
            $valid = $ffi->nntmux_cat_rgb_cmd_valid_backfill_type($type);
        } catch (Throwable) {
            return self::isValidBackfillTypePhp($type);
        }

        return $valid === 0 || $valid === 1 ? $valid === 1 : self::isValidBackfillTypePhp($type);
    }

    /**
     * The given quantity as an integer; otherwise `''` (date-based) for interval backfills or the configured default.
     */
    public static function resolveQuantity(?string $quantity, int $type, int $defaultBackfillQty): int|string
    {
        return self::$useRustFfi
            ? self::resolveQuantityNative($quantity, $type, $defaultBackfillQty)
            : self::resolveQuantityPhp($quantity, $type, $defaultBackfillQty);
    }

    public static function resolveQuantityPhp(?string $quantity, int $type, int $defaultBackfillQty): int|string
    {
        if ($quantity !== null) {
            return (int) $quantity;
        }

        return $type === 1 ? '' : $defaultBackfillQty;
    }

    public static function resolveQuantityNative(?string $quantity, int $type, int $defaultBackfillQty): int|string
    {
        $ffi = self::ffi();
        if ($ffi === null || PHP_INT_SIZE !== 8) {
            return self::resolveQuantityPhp($quantity, $type, $defaultBackfillQty);
        }

        try {
            $value = $ffi->new('int64_t[1]');
            $text = $quantity ?? '';
            // @phpstan-ignore method.notFound
            $kind = $ffi->nntmux_cat_rgb_cmd_resolve_quantity($quantity === null ? 0 : 1, $text, \strlen($text), $type, $defaultBackfillQty, $value);

            return match ($kind) {
                0 => (int) $value[0],
                1 => '',
                default => self::resolveQuantityPhp($quantity, $type, $defaultBackfillQty),
            };
        } catch (Throwable) {
            return self::resolveQuantityPhp($quantity, $type, $defaultBackfillQty);
        }
    }

    public static function formatNntpErrorMessage(string $message): string
    {
        return self::$useRustFfi ? self::formatNntpErrorMessageNative($message) : self::formatNntpErrorMessagePhp($message);
    }

    public static function formatNntpErrorMessagePhp(string $message): string
    {
        return "NNTP connection failed: {$message}";
    }

    public static function formatNntpErrorMessageNative(string $message): string
    {
        $ffi = self::ffi();
        if ($ffi === null) {
            return self::formatNntpErrorMessagePhp($message);
        }

        return self::callForString($ffi, 'nntmux_cat_rgb_cmd_message', \strlen($message) + 32, 0, $message, \strlen($message))
            ?? self::formatNntpErrorMessagePhp($message);
    }

    /**
     * Calls a byte-output export with a scratch buffer, growing it once when Rust reports a larger requirement.
     */
    private static function callForString(FFI $ffi, string $symbol, int $estimate, mixed ...$args): ?string
    {
        try {
            $capacity = 0;
            $outBuf = RustPipelineLibrary::obtainScratch($ffi, max(1, $estimate), $capacity);
            if ($outBuf === null) {
                return null;
            }
            $written = $ffi->$symbol(...[...$args, $outBuf, $capacity]);
            if (\is_int($written) && $written < -1 && -$written > $capacity) {
                $outBuf = RustPipelineLibrary::obtainScratch($ffi, -$written, $capacity);
                if ($outBuf === null) {
                    return null;
                }
                $written = $ffi->$symbol(...[...$args, $outBuf, $capacity]);
            }
            if (! \is_int($written) || $written < 0 || $written > $capacity) {
                return null;
            }

            return $written === 0 ? '' : FFI::string($outBuf, $written);
        } catch (Throwable) {
            return null;
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
            $ffi = RustPipelineLibrary::resolveDomain('w30-3-backfill-group', self::FFI_CDEF, getenv('NNTMUX_PIPELINE_FFI_LIB') ?: null);
        } catch (Throwable) {
            $ffi = null;
        }
        self::$ffi = $ffi ?? false;

        return $ffi;
    }
}
