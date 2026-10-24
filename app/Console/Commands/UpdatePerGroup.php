<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\UsenetGroup;
use App\Services\AdditionalProcessing\AdditionalProcessingOrchestrator;
use App\Services\Backfill\BackfillService;
use App\Services\Binaries\BinariesService;
use App\Services\Configuration\ConfigurationProvider;
use App\Services\Configuration\ProcessingRuntimeStateRepository;
use App\Services\NfoService;
use App\Services\NNTP\NNTPService;
use App\Services\ReleaseProcessingService;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Console\Attribute\AsCommand;
use Throwable;

#[AsCommand(name: 'group:update-all')]
class UpdatePerGroup extends Command
{
    public const string FFI_CDEF = <<<'CDEF'
        int32_t nntmux_cat_gmr_cmd_is_numeric(uint8_t kind, const char *text_ptr, size_t text_len);
        ssize_t nntmux_cat_gmr_cmd_stage_messages(const char *name_ptr, size_t name_len, size_t *out_lengths, char *out_buf, size_t out_cap);
        CDEF;

    /** Stage message keys, in the order `handle()` reports them. */
    private const array STAGE_KEYS = ['binaries', 'backfill', 'releases', 'additional', 'nfo', 'completed'];

    private static bool $useRustFfi = false;

    private static FFI|false|null $ffi = null;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'group:update-all {groupId : Group ID to process}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Do a single group (update_binaries/backFill/update_releases/postprocess)';

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

        if (! self::isNumericGroupId($groupId)) {
            $this->error('Group ID must be numeric.');

            return self::FAILURE;
        }

        try {
            if (app(ProcessingRuntimeStateRepository::class)->stopRequested()) {
                return self::SUCCESS;
            }
            $group = UsenetGroup::find($groupId);

            if ($group === null) {
                $this->error("Group not found with id {$groupId}");

                return self::FAILURE;
            }

            $groupMySQL = $group->toArray();
            $messages = self::formatStageProgressMessages((string) $groupMySQL['name']);
            $nntp = $this->getNntp();
            $backfillService = new BackfillService(nntp: $nntp);

            // Update the group for new binaries
            $this->info($messages['binaries']);
            (new BinariesService)->updateGroup($groupMySQL);
            if (app(ProcessingRuntimeStateRepository::class)->stopRequested()) {
                return self::SUCCESS;
            }

            // BackFill the group with 20k articles
            $this->info($messages['backfill']);
            $backfillService->backfillAllGroups($groupMySQL['name'], 20000, 'normal');
            if (app(ProcessingRuntimeStateRepository::class)->stopRequested()) {
                return self::SUCCESS;
            }

            // Create releases
            $this->info($messages['releases']);
            $this->processReleases((string) $groupId);
            if (app(ProcessingRuntimeStateRepository::class)->stopRequested()) {
                return self::SUCCESS;
            }

            // Post process the releases
            $this->info($messages['additional']);
            $orchestrator = app(AdditionalProcessingOrchestrator::class);
            try {
                $orchestrator->start($groupId);
            } finally {
                $orchestrator->finish();
            }
            if (app(ProcessingRuntimeStateRepository::class)->stopRequested()) {
                return self::SUCCESS;
            }

            $this->info($messages['nfo']);
            (new NfoService)->processNfoFiles(
                $nntp,
                $groupId,
                '',
                (bool) app(ConfigurationProvider::class)->metadata()->movieLookup->value,
                (bool) app(ConfigurationProvider::class)->metadata()->tvLookup->value
            );

            $this->info($messages['completed']);

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
    private function processReleases(string $groupID): void
    {
        $this->releaseProcessingService->processCollectionsAndReleases($groupID);

        if (! app(ProcessingRuntimeStateRepository::class)->stopRequested()) {
            $this->releaseProcessingService->deleteCollections($groupID);
        }
    }

    /**
     * Get NNTP connection.
     *
     * @throws \Exception If unable to connect to usenet
     */
    private function getNntp(): NNTPService
    {
        $nntp = new NNTPService;

        $useAlternate = config('nntmux_nntp.use_alternate_nntp_server') === true;
        $connectResult = $useAlternate
            ? $nntp->doConnect(false, true)
            : $nntp->doConnect();

        if ($connectResult !== true) {
            $errorMessage = 'Unable to connect to usenet.';
            if (NNTPService::isError($connectResult)) {
                $errorMessage .= ' Error: '.$connectResult->getMessage();
            }
            throw new \RuntimeException($errorMessage);
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
     * PHP `is_numeric()` for the group id argument.
     */
    public static function isNumericGroupId(mixed $groupId): bool
    {
        return self::$useRustFfi ? self::isNumericGroupIdNative($groupId) : self::isNumericGroupIdPhp($groupId);
    }

    public static function isNumericGroupIdPhp(mixed $groupId): bool
    {
        return is_numeric($groupId);
    }

    public static function isNumericGroupIdNative(mixed $groupId): bool
    {
        $ffi = self::ffi();
        $arguments = match (true) {
            $groupId === null => [0, '', 0],
            \is_bool($groupId) => [1, '', 0],
            \is_int($groupId) => [2, '', 0],
            \is_float($groupId) => [3, '', 0],
            \is_string($groupId) => [4, $groupId, \strlen($groupId)],
            default => null,
        };
        if ($ffi === null || $arguments === null) {
            return self::isNumericGroupIdPhp($groupId);
        }

        try {
            // @phpstan-ignore method.notFound
            $rc = $ffi->nntmux_cat_gmr_cmd_is_numeric(...$arguments);

            return $rc === 0 || $rc === 1 ? $rc === 1 : self::isNumericGroupIdPhp($groupId);
        } catch (Throwable) {
            return self::isNumericGroupIdPhp($groupId);
        }
    }

    /**
     * The progress messages `handle()` reports for each processing stage.
     *
     * @return array{binaries: string, backfill: string, releases: string, additional: string, nfo: string, completed: string}
     */
    public static function formatStageProgressMessages(string $groupName): array
    {
        return self::$useRustFfi ? self::formatStageProgressMessagesNative($groupName) : self::formatStageProgressMessagesPhp($groupName);
    }

    /**
     * @return array{binaries: string, backfill: string, releases: string, additional: string, nfo: string, completed: string}
     */
    public static function formatStageProgressMessagesPhp(string $groupName): array
    {
        return [
            'binaries' => "Updating binaries for group: {$groupName}",
            'backfill' => "Backfilling group: {$groupName}",
            'releases' => "Processing releases for group: {$groupName}",
            'additional' => "Post-processing additional for group: {$groupName}",
            'nfo' => "Processing NFO files for group: {$groupName}",
            'completed' => "Completed all processing for group: {$groupName}",
        ];
    }

    /**
     * @return array{binaries: string, backfill: string, releases: string, additional: string, nfo: string, completed: string}
     */
    public static function formatStageProgressMessagesNative(string $groupName): array
    {
        $ffi = self::ffi();
        if ($ffi === null) {
            return self::formatStageProgressMessagesPhp($groupName);
        }

        try {
            $lengths = $ffi->new('size_t[6]');
            $joined = self::callForString($ffi, 'nntmux_cat_gmr_cmd_stage_messages', 6 * \strlen($groupName) + 256, $groupName, \strlen($groupName), $lengths);
            if ($joined === null) {
                return self::formatStageProgressMessagesPhp($groupName);
            }
            $messages = [];
            $offset = 0;
            foreach (self::STAGE_KEYS as $index => $key) {
                $length = (int) $lengths[$index];
                $messages[$key] = substr($joined, $offset, $length);
                $offset += $length;
            }
            if ($offset !== \strlen($joined)) {
                return self::formatStageProgressMessagesPhp($groupName);
            }

            /** @var array{binaries: string, backfill: string, releases: string, additional: string, nfo: string, completed: string} $messages */
            return $messages;
        } catch (Throwable) {
            return self::formatStageProgressMessagesPhp($groupName);
        }
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
            $ffi = RustPipelineLibrary::resolveDomain('w31-3-update-per-group', self::FFI_CDEF, getenv('NNTMUX_PIPELINE_FFI_LIB') ?: null);
        } catch (Throwable) {
            $ffi = null;
        }
        self::$ffi = $ffi ?? false;

        return $ffi;
    }
}
