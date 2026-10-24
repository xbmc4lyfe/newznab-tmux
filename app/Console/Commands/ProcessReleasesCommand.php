<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Configuration\ProcessingRuntimeStateRepository;
use App\Services\ReleaseProcessingService;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Console\Attribute\AsCommand;
use Throwable;

#[AsCommand(name: 'releases:process')]
class ProcessReleasesCommand extends Command
{
    public const string FFI_CDEF = <<<'CDEF'
        int32_t nntmux_cat_gmr_cmd_execution_plan(uint8_t group_present, const char *group_ptr, size_t group_len, uint8_t ready_only, uint8_t maintenance_only, int64_t *out_group_id);
        CDEF;

    public const string ERROR_GROUP_ID = 'Group ID must be a positive integer.';

    public const string ERROR_MAINTENANCE_WITH_GROUP = 'The --maintenance-only option cannot be combined with a group ID.';

    public const string ERROR_READY_ONLY = 'The --ready-only option requires a group ID and cannot be combined with --maintenance-only.';

    private static bool $useRustFfi = false;

    private static FFI|false|null $ffi = null;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'releases:process
                            {groupId? : Group ID to process (optional)}
                            {--maintenance-only : Run release cleanup and categorization without processing collections}
                            {--ready-only : Process one bounded ready window for a group without reconciliation or retention cleanup}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Process releases for a specific group or all groups';

    public function __construct(
        private readonly ReleaseProcessingService $releaseProcessingService
    ) {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $groupId = $this->argument('groupId');
        $plan = self::resolveExecutionPlan(
            $groupId === null ? null : (string) $groupId,
            (bool) $this->option('ready-only'),
            (bool) $this->option('maintenance-only'),
        );

        if (! $plan['valid']) {
            $this->error((string) $plan['error']);

            return self::FAILURE;
        }

        try {
            if (app(ProcessingRuntimeStateRepository::class)->stopRequested()) {
                return self::SUCCESS;
            }
            if ($plan['mode'] === 'ready_only') {
                $this->releaseProcessingService->processReadyReleaseWindow((int) $plan['group_id']);
            } elseif ($plan['mode'] === 'maintenance_only') {
                $this->runPostProcessingTasks();
            } elseif ($plan['mode'] === 'group') {
                $this->processReleasesForGroup((string) $groupId);
            } else {
                $this->processReleasesForGroup('');
                $this->runPostProcessingTasks();
            }

            return self::SUCCESS;
        } catch (Throwable $e) {
            Log::error($e->getTraceAsString());
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }

    /**
     * Create / process releases for a groupID.
     */
    private function processReleasesForGroup(string $groupID): void
    {
        $this->releaseProcessingService->processCollectionsAndReleases($groupID);

        if (! app(ProcessingRuntimeStateRepository::class)->stopRequested()) {
            $this->releaseProcessingService->deleteCollections($groupID);
        }
    }

    /**
     * Run post-processing tasks after all group releases are processed.
     */
    private function runPostProcessingTasks(): void
    {
        if (app(ProcessingRuntimeStateRepository::class)->stopRequested()) {
            return;
        }
        $this->releaseProcessingService->deletedReleasesByGroup();
        if (app(ProcessingRuntimeStateRepository::class)->stopRequested()) {
            return;
        }
        $this->releaseProcessingService->deleteReleases();
        if (app(ProcessingRuntimeStateRepository::class)->stopRequested()) {
            return;
        }
        $this->releaseProcessingService->categorizeReleases(2);
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
     * Validates the argument and options in `handle()` order and selects the processing mode:
     * `ready_only`, `maintenance_only`, `group`, `all`, or `invalid` with its error message.
     *
     * @return array{valid: bool, mode: string, group_id: ?int, error: ?string}
     */
    public static function resolveExecutionPlan(?string $groupId, bool $readyOnly, bool $maintenanceOnly): array
    {
        return self::$useRustFfi
            ? self::resolveExecutionPlanNative($groupId, $readyOnly, $maintenanceOnly)
            : self::resolveExecutionPlanPhp($groupId, $readyOnly, $maintenanceOnly);
    }

    /**
     * @return array{valid: bool, mode: string, group_id: ?int, error: ?string}
     */
    public static function resolveExecutionPlanPhp(?string $groupId, bool $readyOnly, bool $maintenanceOnly): array
    {
        if ($groupId !== null && (! ctype_digit($groupId) || (int) $groupId <= 0)) {
            return self::invalidPlan(self::ERROR_GROUP_ID);
        }

        if ($maintenanceOnly && $groupId !== null) {
            return self::invalidPlan(self::ERROR_MAINTENANCE_WITH_GROUP);
        }

        if ($readyOnly && ($groupId === null || $maintenanceOnly)) {
            return self::invalidPlan(self::ERROR_READY_ONLY);
        }

        return match (true) {
            $readyOnly => ['valid' => true, 'mode' => 'ready_only', 'group_id' => (int) $groupId, 'error' => null],
            $maintenanceOnly => ['valid' => true, 'mode' => 'maintenance_only', 'group_id' => null, 'error' => null],
            $groupId !== null => ['valid' => true, 'mode' => 'group', 'group_id' => (int) $groupId, 'error' => null],
            default => ['valid' => true, 'mode' => 'all', 'group_id' => null, 'error' => null],
        };
    }

    /**
     * @return array{valid: bool, mode: string, group_id: ?int, error: ?string}
     */
    public static function resolveExecutionPlanNative(?string $groupId, bool $readyOnly, bool $maintenanceOnly): array
    {
        $ffi = self::ffi();
        if ($ffi === null || PHP_INT_SIZE !== 8) {
            return self::resolveExecutionPlanPhp($groupId, $readyOnly, $maintenanceOnly);
        }

        try {
            $value = $ffi->new('int64_t[1]');
            // @phpstan-ignore method.notFound
            $code = $ffi->nntmux_cat_gmr_cmd_execution_plan(
                $groupId === null ? 0 : 1,
                $groupId ?? '',
                $groupId === null ? 0 : \strlen($groupId),
                $readyOnly ? 1 : 0,
                $maintenanceOnly ? 1 : 0,
                $value,
            );

            return match ($code) {
                0 => ['valid' => true, 'mode' => 'all', 'group_id' => null, 'error' => null],
                1 => ['valid' => true, 'mode' => 'group', 'group_id' => (int) $value[0], 'error' => null],
                2 => ['valid' => true, 'mode' => 'maintenance_only', 'group_id' => null, 'error' => null],
                3 => ['valid' => true, 'mode' => 'ready_only', 'group_id' => (int) $value[0], 'error' => null],
                4 => self::invalidPlan(self::ERROR_GROUP_ID),
                5 => self::invalidPlan(self::ERROR_MAINTENANCE_WITH_GROUP),
                6 => self::invalidPlan(self::ERROR_READY_ONLY),
                default => self::resolveExecutionPlanPhp($groupId, $readyOnly, $maintenanceOnly),
            };
        } catch (Throwable) {
            return self::resolveExecutionPlanPhp($groupId, $readyOnly, $maintenanceOnly);
        }
    }

    /**
     * @return array{valid: false, mode: 'invalid', group_id: null, error: string}
     */
    private static function invalidPlan(string $error): array
    {
        return ['valid' => false, 'mode' => 'invalid', 'group_id' => null, 'error' => $error];
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
            $ffi = RustPipelineLibrary::resolveDomain('w31-3-process-releases', self::FFI_CDEF, getenv('NNTMUX_PIPELINE_FFI_LIB') ?: null);
        } catch (Throwable) {
            $ffi = null;
        }
        self::$ffi = $ffi ?? false;

        return $ffi;
    }
}
