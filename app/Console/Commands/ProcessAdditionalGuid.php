<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Facades\Search;
use App\Models\Release;
use App\Services\AdditionalProcessing\AdditionalProcessingOrchestrator;
use App\Services\AdditionalProcessing\DTO\ReleaseProcessingResult;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Console\Command;
use Throwable;

class ProcessAdditionalGuid extends Command
{
    private const FFI_CDEF = <<<'CDEF'
ssize_t nntmux_process_additional_validate_identifier(
    uint8_t has_guid,
    const char *guid_ptr,
    size_t guid_len,
    uint8_t has_id,
    const char *id_ptr,
    size_t id_len,
    int64_t *out_scalars,
    uint8_t *out_buf,
    size_t out_cap
);
int32_t nntmux_process_additional_reset_flags(int64_t *out_scalars);
CDEF;

    private const ERROR_MISSING_IDENTIFIER = 'You must supply either a GUID argument or --id=<release id>.';

    private const ERROR_CONFLICTING_IDENTIFIERS = 'Provide only one identifier: GUID or --id, not both.';

    private const ERROR_NON_NUMERIC_ID = 'Release ID must be numeric.';

    /**
     * Rust FFI stays opt-in: identifier validation is too small for FFI to beat PHP by 1.5x.
     */
    private static bool $useRustFfi = false;

    private static FFI|false|null $ffi = null;

    protected $signature = 'releases:additional
                                {guid? : Release GUID (optional if using --id)}
                                {--id= : Numeric release ID to run additional postprocessing on (alternative to GUID)}
                                {--reset : Reset key additional postprocessing flags before running}
                                {--no-progress : Suppress progress / status output (forces quiet mode)}
                                {--profile : Display stage, network, and persistence metrics}';

    protected $description = 'Run additional postprocessing for a specific release by GUID or ID regardless of prior postprocessing status';

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
        $guidArg = $this->argument('guid');
        $idOpt = $this->option('id');

        $validation = self::validateIdentifierInput(
            $guidArg !== null ? (string) $guidArg : null,
            $idOpt !== null ? (string) $idOpt : null
        );

        if (! $validation['valid']) {
            $this->error((string) $validation['error']);

            return $validation['exit_code']; // 1 missing, 4 conflicting, 5 invalid id format
        }

        if ($validation['mode'] === 'id') {
            $release = Release::find($validation['id']);
            if ($release === null) {
                $this->error('Release not found for ID: '.$idOpt);

                return 2; // not found
            }
            $guid = $release->guid;
        } else {
            $guid = (string) $validation['guid'];
            $release = Release::where('guid', $guid)->first();
            if ($release === null) {
                $this->error('Release not found for GUID: '.$guid);

                return 2;
            }
        }

        if ($this->option('reset')) {
            Release::where('id', $release->id)->update(self::buildResetFlagsPayload());
            Search::updateRelease((int) $release->id);
            $this->info('Reset postprocessing flags for release ID '.$release->id.' (GUID '.$guid.')');
        }

        if ($this->option('no-progress')) {
            config(['nntmux.echocli' => false]);
        }

        $processor = app(AdditionalProcessingOrchestrator::class);

        try {
            $result = $processor->processSingleGuid($guid);
        } finally {
            $processor->finish();
        }

        $identifier = $idOpt ? 'ID '.$idOpt : 'GUID '.$guid;

        $this->displayResult($result, $identifier);

        if ($this->option('profile')) {
            $this->displayProfile($result);
        }

        if (! $result->isSuccessful()) {
            return 3;
        }

        return 0;
    }

    /**
     * @return array{valid: bool, mode: 'id'|'guid'|null, id: int|null, guid: string|null, exit_code: int, error: string|null}
     */
    public static function validateIdentifierInput(?string $guidArg, ?string $idOption): array
    {
        if (self::$useRustFfi) {
            return self::validateIdentifierInputNative($guidArg, $idOption);
        }

        return self::validateIdentifierInputPhp($guidArg, $idOption);
    }

    /**
     * @return array{valid: bool, mode: 'id'|'guid'|null, id: int|null, guid: string|null, exit_code: int, error: string|null}
     */
    public static function validateIdentifierInputPhp(?string $guidArg, ?string $idOption): array
    {
        $guid = trim((string) $guidArg);
        $hasId = $idOption !== null && $idOption !== '';

        if ($guid === '' && ! $hasId) {
            return self::identifierFailure(1, self::ERROR_MISSING_IDENTIFIER);
        }

        if ($guid !== '' && $hasId) {
            return self::identifierFailure(4, self::ERROR_CONFLICTING_IDENTIFIERS);
        }

        if ($hasId) {
            if (! ctype_digit($idOption)) {
                return self::identifierFailure(5, self::ERROR_NON_NUMERIC_ID);
            }

            return ['valid' => true, 'mode' => 'id', 'id' => (int) $idOption, 'guid' => null, 'exit_code' => 0, 'error' => null];
        }

        return ['valid' => true, 'mode' => 'guid', 'id' => null, 'guid' => $guid, 'exit_code' => 0, 'error' => null];
    }

    /**
     * @return array{valid: bool, mode: 'id'|'guid'|null, id: int|null, guid: string|null, exit_code: int, error: string|null}
     */
    public static function validateIdentifierInputNative(?string $guidArg, ?string $idOption): array
    {
        $ffi = self::additionalFfi();
        if (! $ffi instanceof FFI) {
            return self::validateIdentifierInputPhp($guidArg, $idOption);
        }

        try {
            $guidStr = $guidArg ?? '';
            $idStr = $idOption ?? '';
            $cap = max(128, strlen($guidStr) + 16);
            $outScalars = $ffi->new('int64_t[4]');
            $outBuf = $ffi->new("uint8_t[{$cap}]");

            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_process_additional_validate_identifier(
                $guidArg !== null ? 1 : 0,
                $guidStr,
                strlen($guidStr),
                $idOption !== null ? 1 : 0,
                $idStr,
                strlen($idStr),
                $outScalars,
                $outBuf,
                $cap
            );
            $valid = $outScalars[0];
            $mode = $outScalars[1];
            $id = $outScalars[2];
            $exitCode = $outScalars[3];
            if (! is_int($written) || $written < 0 || ! is_int($valid) || ! is_int($mode) || ! is_int($id) || ! is_int($exitCode)) {
                return self::validateIdentifierInputPhp($guidArg, $idOption);
            }
            $text = $written > 0 ? FFI::string($outBuf, $written) : '';

            if ($valid === 0 && $mode === 0 && in_array($exitCode, [1, 4, 5], true) && $text !== '') {
                return self::identifierFailure($exitCode, $text);
            }
            if ($valid === 1 && $mode === 1 && $exitCode === 0) {
                return ['valid' => true, 'mode' => 'id', 'id' => $id, 'guid' => null, 'exit_code' => 0, 'error' => null];
            }
            if ($valid === 1 && $mode === 2 && $exitCode === 0 && $text !== '') {
                return ['valid' => true, 'mode' => 'guid', 'id' => null, 'guid' => $text, 'exit_code' => 0, 'error' => null];
            }

            return self::validateIdentifierInputPhp($guidArg, $idOption);
        } catch (Throwable) {
            return self::validateIdentifierInputPhp($guidArg, $idOption);
        }
    }

    /**
     * @return array{passwordstatus: int, haspreview: int, archive_retry_at: null, jpgstatus: int, videostatus: int, nfostatus: int}
     */
    public static function buildResetFlagsPayload(): array
    {
        if (self::$useRustFfi) {
            return self::buildResetFlagsPayloadNative();
        }

        return self::buildResetFlagsPayloadPhp();
    }

    /**
     * @return array{passwordstatus: int, haspreview: int, archive_retry_at: null, jpgstatus: int, videostatus: int, nfostatus: int}
     */
    public static function buildResetFlagsPayloadPhp(): array
    {
        return [
            'passwordstatus' => -1,
            'haspreview' => -1,
            'archive_retry_at' => null,
            'jpgstatus' => 0,
            'videostatus' => 0,
            'nfostatus' => -1,
        ];
    }

    /**
     * @return array{passwordstatus: int, haspreview: int, archive_retry_at: null, jpgstatus: int, videostatus: int, nfostatus: int}
     */
    public static function buildResetFlagsPayloadNative(): array
    {
        $ffi = self::additionalFfi();
        if (! $ffi instanceof FFI) {
            return self::buildResetFlagsPayloadPhp();
        }

        try {
            $outScalars = $ffi->new('int64_t[5]');

            // @phpstan-ignore method.notFound
            $count = $ffi->nntmux_process_additional_reset_flags($outScalars);
            $flags = [$outScalars[0], $outScalars[1], $outScalars[2], $outScalars[3], $outScalars[4]];
            if ($count !== 5 || array_filter($flags, 'is_int') !== $flags) {
                return self::buildResetFlagsPayloadPhp();
            }

            return [
                'passwordstatus' => $flags[0],
                'haspreview' => $flags[1],
                'archive_retry_at' => null,
                'jpgstatus' => $flags[2],
                'videostatus' => $flags[3],
                'nfostatus' => $flags[4],
            ];
        } catch (Throwable) {
            return self::buildResetFlagsPayloadPhp();
        }
    }

    /**
     * @return array{valid: false, mode: null, id: null, guid: null, exit_code: int, error: string}
     */
    private static function identifierFailure(int $exitCode, string $error): array
    {
        return ['valid' => false, 'mode' => null, 'id' => null, 'guid' => null, 'exit_code' => $exitCode, 'error' => $error];
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

    private function displayResult(ReleaseProcessingResult $result, string $identifier): void
    {
        $rows = [
            ['Release', $identifier],
            ['Pipeline', 'v2'],
            ['Outcome', $result->outcome->value],
            ['Artifacts created', $result->artifactsCreated ? 'yes' : 'no'],
            ['Release files added', (string) $result->releaseFilesAdded],
            ['Duration', number_format($result->elapsedSeconds, 3).' seconds'],
        ];

        if ($result->reason !== '') {
            $rows[] = ['Reason', $result->reason];
        }

        $this->table(['Result', 'Value'], $rows);

        $message = 'Additional postprocessing ended with outcome '.$result->outcome->value.' for '.$identifier
            .' after '.number_format($result->elapsedSeconds, 3).' seconds.';

        if ($result->isSuccessful()) {
            $this->info($message);

            return;
        }

        $this->error($message);
    }

    private function displayProfile(ReleaseProcessingResult $result): void
    {
        $stageRows = [];
        foreach ($result->stageDurations as $stage => $duration) {
            $stageRows[] = [$stage, number_format($duration, 3)];
        }

        if ($stageRows !== []) {
            $this->newLine();
            $this->line('Stage profile');
            $this->table(['Stage', 'Seconds'], $stageRows);
        }

        if ($result->downloadMetrics !== null) {
            $this->newLine();
            $this->line('Download profile');
            $this->table(['Metric', 'Value'], [
                ['Logical requests', (string) $result->downloadMetrics->logicalRequests],
                ['Network requests', (string) $result->downloadMetrics->networkRequests],
                ['Cache hits', (string) $result->downloadMetrics->cacheHits],
                ['Bytes downloaded', (string) $result->downloadMetrics->bytesDownloaded],
                ['Bytes reused', (string) $result->downloadMetrics->bytesReused],
                ['Bytes for previews', (string) $result->downloadMetrics->mediaBytesDownloaded],
            ]);
        }

        if ($result->persistenceMetrics !== null) {
            $this->newLine();
            $this->line('Persistence profile');
            $this->table(['Metric', 'Value'], [
                ['Database statements', (string) $result->persistenceMetrics->databaseStatements],
                ['Database milliseconds', number_format($result->persistenceMetrics->databaseMilliseconds, 3)],
                ['Search sync requests', (string) $result->persistenceMetrics->searchSyncRequests],
                ['Search sync executions', (string) $result->persistenceMetrics->searchSyncExecutions],
            ]);
        }
    }
}
