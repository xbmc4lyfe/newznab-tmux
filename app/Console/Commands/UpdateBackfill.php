<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Backfill\BackfillService;
use App\Services\NNTP\NNTPService;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Console\Command;
use Throwable;

class UpdateBackfill extends Command
{
    public const string FFI_CDEF = <<<'CDEF'
        int32_t nntmux_cat_rgb_cmd_is_group_name(const char *mode_ptr, size_t mode_len);
        int32_t nntmux_cat_rgb_cmd_backfill_plan(const char *mode_ptr, size_t mode_len, uint8_t has_quantity);
        CDEF;

    /** Plan actions indexed by the Rust action code. */
    private const array PLAN_ACTIONS = ['backfill_all', 'backfill_alph', 'backfill_date', 'backfill_safe', 'group_date', 'group_quantity', 'invalid'];

    /** `BackfillService::backfillAllGroups()` types indexed by the Rust type code. */
    private const array PLAN_TYPES = ['', 'normal', 'date'];

    private static bool $useRustFfi = false;

    private static FFI|false|null $ffi = null;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'update:backfill
                            {mode=all : Mode: all, alph, date, safe, or group name}
                            {quantity? : Number of articles to backfill}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Backfill groups by various methods';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $mode = $this->argument('mode');
        $quantity = $this->argument('quantity');

        try {
            $nntp = $this->getNntp();
            $backfill = new BackfillService(nntp: $nntp);

            // A non-numeric quantity matches no mode.
            $plan = \is_string($mode) && ($quantity === null || is_numeric($quantity))
                ? self::resolveBackfillDispatchPlan($mode, $quantity === null ? null : (int) $quantity)
                : ['action' => 'invalid', 'type' => '', 'quantity' => null];

            match ($plan['action']) {
                'backfill_all' => $backfill->backfillAllGroups(),
                'backfill_alph', 'backfill_date' => $backfill->backfillAllGroups('', (int) $plan['quantity'], $plan['type']),
                'backfill_safe' => $backfill->safeBackfill((int) $plan['quantity']),
                'group_date' => $backfill->backfillAllGroups((string) $mode),
                'group_quantity' => $backfill->backfillAllGroups((string) $mode, (int) $plan['quantity']),
                default => throw new \Exception($this->getHelpText()),
            };

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }

    /**
     * Get help text.
     */
    private function getHelpText(): string
    {
        return "Wrong set of arguments.\n\n"
            ."Examples:\n"
            ."  php artisan update:backfill all                           - Backfills all groups 1 at a time, by date\n"
            ."  php artisan update:backfill alt.binaries.ath              - Backfills a group by name, by date\n"
            ."  php artisan update:backfill alt.binaries.ath 200000       - Backfills a group by name by number of articles\n"
            ."  php artisan update:backfill alph 200000                   - Backfills all groups (alphabetically) by number of articles\n"
            ."  php artisan update:backfill date 200000                   - Backfills all groups (by least backfilled) by number of articles\n"
            ."  php artisan update:backfill safe 200000                   - Safe backfill, stops at 2012-06-24\n";
    }

    /**
     * Get NNTP connection.
     */
    protected function getNntp(): NNTPService
    {
        $nntp = new NNTPService;

        $connectResult = $nntp->doConnect();

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
     * A `group.name` argument rather than one of the `all`, `alph`, `date` or `safe` modes.
     */
    public static function isGroupNameArgument(string $mode): bool
    {
        return self::$useRustFfi ? self::isGroupNameArgumentNative($mode) : self::isGroupNameArgumentPhp($mode);
    }

    public static function isGroupNameArgumentPhp(string $mode): bool
    {
        return ! \in_array($mode, ['all', 'alph', 'date', 'safe'], true)
            && preg_match('/^[a-z0-9+_.-]+\.[a-z0-9+_.-]+$/i', $mode) === 1;
    }

    public static function isGroupNameArgumentNative(string $mode): bool
    {
        $ffi = self::ffi();
        if ($ffi === null) {
            return self::isGroupNameArgumentPhp($mode);
        }

        try {
            // @phpstan-ignore method.notFound
            $isGroup = $ffi->nntmux_cat_rgb_cmd_is_group_name($mode, \strlen($mode));
        } catch (Throwable) {
            return self::isGroupNameArgumentPhp($mode);
        }

        return $isGroup === 0 || $isGroup === 1 ? $isGroup === 1 : self::isGroupNameArgumentPhp($mode);
    }

    /**
     * Which backfill call a mode and optional numeric quantity select; `invalid` shows the help text.
     *
     * @return array{action: string, type: string, quantity: int|null}
     */
    public static function resolveBackfillDispatchPlan(string $mode, ?int $quantity): array
    {
        return self::$useRustFfi
            ? self::resolveBackfillDispatchPlanNative($mode, $quantity)
            : self::resolveBackfillDispatchPlanPhp($mode, $quantity);
    }

    /**
     * @return array{action: string, type: string, quantity: int|null}
     */
    public static function resolveBackfillDispatchPlanPhp(string $mode, ?int $quantity): array
    {
        $isGroupName = self::isGroupNameArgumentPhp($mode);
        [$action, $type] = match (true) {
            $mode === 'all' && $quantity === null => ['backfill_all', ''],
            $mode === 'alph' && $quantity !== null => ['backfill_alph', 'normal'],
            $mode === 'date' && $quantity !== null => ['backfill_date', 'date'],
            $mode === 'safe' && $quantity !== null => ['backfill_safe', ''],
            $isGroupName && $quantity === null => ['group_date', ''],
            $isGroupName && $quantity !== null => ['group_quantity', ''],
            default => ['invalid', ''],
        };

        return [
            'action' => $action,
            'type' => $type,
            'quantity' => \in_array($action, ['backfill_alph', 'backfill_date', 'backfill_safe', 'group_quantity'], true) ? $quantity : null,
        ];
    }

    /**
     * @return array{action: string, type: string, quantity: int|null}
     */
    public static function resolveBackfillDispatchPlanNative(string $mode, ?int $quantity): array
    {
        $ffi = self::ffi();
        if ($ffi === null) {
            return self::resolveBackfillDispatchPlanPhp($mode, $quantity);
        }

        try {
            // @phpstan-ignore method.notFound
            $packed = $ffi->nntmux_cat_rgb_cmd_backfill_plan($mode, \strlen($mode), $quantity === null ? 0 : 1);
        } catch (Throwable) {
            return self::resolveBackfillDispatchPlanPhp($mode, $quantity);
        }
        if (! \is_int($packed) || $packed < 0 || ($packed & ~0x13F) !== 0) {
            return self::resolveBackfillDispatchPlanPhp($mode, $quantity);
        }
        $action = self::PLAN_ACTIONS[$packed & 0xF] ?? null;
        $type = self::PLAN_TYPES[($packed >> 4) & 0x3] ?? null;
        if ($action === null || $type === null) {
            return self::resolveBackfillDispatchPlanPhp($mode, $quantity);
        }

        return ['action' => $action, 'type' => $type, 'quantity' => ($packed & 0x100) !== 0 ? $quantity : null];
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
            $ffi = RustPipelineLibrary::resolveDomain('w30-3-update-backfill', self::FFI_CDEF, getenv('NNTMUX_PIPELINE_FFI_LIB') ?: null);
        } catch (Throwable) {
            $ffi = null;
        }
        self::$ffi = $ffi ?? false;

        return $ffi;
    }
}
