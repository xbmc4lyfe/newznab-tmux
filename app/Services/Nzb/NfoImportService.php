<?php

declare(strict_types=1);

namespace App\Services\Nzb;

use App\Exceptions\NfoPayloadRejected;
use App\Models\Release;
use App\Services\NfoService;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

final class NfoImportService
{
    public const string FFI_CDEF = <<<'C'
        intptr_t nntmux_w17_nzb_xml_verification_timing(uint32_t operation, const char *input, size_t length, char *output, size_t capacity);
        C;

    // Paired common/uncommon mean and p99 must all pass before adoption.
    private static bool $useRustFfi = false;

    public static function setUseRustFfi(bool $enabled): void
    {
        self::$useRustFfi = $enabled;
    }

    /** Reject objects before serialization can invoke user code; bound cycles. */
    private static function nativeValueSupported(mixed $value, int &$remaining, int $depth): bool
    {
        if (--$remaining < 0 || $depth > 24) {
            return false;
        }
        if (is_array($value)) {
            foreach ($value as $item) {
                if (! self::nativeValueSupported($item, $remaining, $depth + 1)) {
                    return false;
                }
            }

            return true;
        }

        return $value === null || is_scalar($value);
    }

    /** @param list<mixed> $arguments */
    private static function computeNative(int $operation, array $arguments): mixed
    {
        if (! self::$useRustFfi) {
            return null;
        }
        try {
            $remaining = 100000;
            if (! self::nativeValueSupported($arguments, $remaining, 0)) {
                return null;
            }
            $ffi = RustPipelineLibrary::resolveDomain('nfo', self::FFI_CDEF);
            if ($ffi === null) {
                return null;
            }
            $input = serialize($arguments);
            $capacity = 0;
            $output = RustPipelineLibrary::obtainScratch($ffi, max(4096, strlen($input) + 512), $capacity);
            if ($output === null) {
                return null;
            }
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_w17_nzb_xml_verification_timing($operation, $input, strlen($input), $output, $capacity);
            if (! is_int($written) || $written < 0 || $written > $capacity) {
                return null;
            }

            return unserialize(FFI::string($output, $written), ['allowed_classes' => false]);
        } catch (Throwable) {
            return null;
        }
    }

    /** @param array<string, mixed> $manifest
     * @return array{release_id: int, release_guid: string}|null */
    public static function normalizeManifestEntry(array $manifest): ?array
    {
        return self::normalizeManifestEntryNative($manifest) ?? self::normalizeManifestEntryPhp($manifest);
    }

    /** @param array<string, mixed> $manifest
     * @return array{release_id: int, release_guid: string}|null */
    public static function normalizeManifestEntryPhp(array $manifest): ?array
    {
        $id = $manifest['release_id'] ?? null;
        $guid = $manifest['release_guid'] ?? null;

        return is_int($id) && $id > 0 && self::validGuidPhp($guid)
            ? ['release_id' => $id, 'release_guid' => $guid] : null;
    }

    /** @param array<string, mixed> $manifest
     * @return array{release_id: int, release_guid: string}|null */
    public static function normalizeManifestEntryNative(array $manifest): ?array
    {
        $result = self::computeNative(3, [$manifest]);

        return is_array($result) ? $result : null;
    }

    public static function validGuid(mixed $guid): bool
    {
        return self::validGuidNative($guid) ?? self::validGuidPhp($guid);
    }

    public static function validGuidPhp(mixed $guid): bool
    {
        return is_string($guid) && $guid !== '';
    }

    public static function validGuidNative(mixed $guid): ?bool
    {
        $result = self::computeNative(4, [$guid]);

        return is_bool($result) ? $result : null;
    }

    /** @param array<array-key, mixed> $entries
     * @return array{imported: int, failed: int, skipped: int} */
    public static function importSummary(array $entries): array
    {
        return self::importSummaryNative($entries) ?? self::importSummaryPhp($entries);
    }

    /** @param array<array-key, mixed> $entries
     * @return array{imported: int, failed: int, skipped: int} */
    public static function importSummaryPhp(array $entries): array
    {
        $summary = ['imported' => 0, 'failed' => 0, 'skipped' => 0];
        foreach ($entries as $entry) {
            $status = is_array($entry) ? ($entry['status'] ?? null) : null;
            if (is_string($status) && array_key_exists($status, $summary)) {
                $summary[$status]++;
            }
        }

        return $summary;
    }

    /** @param array<array-key, mixed> $entries
     * @return array{imported: int, failed: int, skipped: int}|null */
    public static function importSummaryNative(array $entries): ?array
    {
        $result = self::computeNative(5, [$entries]);

        return is_array($result) ? $result : null;
    }

    public function __construct(
        private readonly Filesystem $filesystem,
        private readonly NzbUploadManifestService $manifests,
        private readonly NfoService $nfoService,
    ) {}

    /**
     * @return array{status:'imported'|'skipped'|'failed',message:string}
     */
    public function importManifest(string $manifestPath, bool $delete = false, bool $deleteFailed = false): array
    {
        $nfoPath = null;

        try {
            $prior = $this->manifests->read($manifestPath);
            if ($this->manifests->resumeCommittedCompanion($manifestPath, $delete)) {
                return ['status' => $prior['state'] === NzbUploadManifestService::STATE_NFO_IMPORTED ? 'skipped' : 'imported',
                    'message' => 'NFO storage was already committed; cleanup retried'];
            }
            $manifest = $this->manifests->reconcilePending($manifestPath);
            $state = $manifest['state'];

            if ($state === NzbUploadManifestService::STATE_NFO_IMPORTED) {
                $this->manifests->cleanupImportedSource($manifestPath);

                return ['status' => 'skipped', 'message' => 'NFO was already imported'];
            }

            if (in_array($state, [
                NzbUploadManifestService::STATE_METADATA_REVIEW,
                NzbUploadManifestService::STATE_METADATA_DEFERRED,
            ], true)) {
                return ['status' => 'skipped', 'message' => "NFO is held for metadata ({$state})"];
            }

            if (in_array($state, [
                NzbUploadManifestService::STATE_NZB_DUPLICATE,
                NzbUploadManifestService::STATE_NZB_FAILED,
                NzbUploadManifestService::STATE_NZB_REJECTED,
            ], true)) {
                if (($deleteFailed || isset($manifest['delete_terminal_nfo'])) && is_array($manifest['nfo'] ?? null)) {
                    try {
                        $this->manifests->deleteTerminalCompanion($manifestPath);
                    } catch (Throwable) {
                        // The terminal NZB outcome remains authoritative even if its NFO is already gone.
                    }
                }

                return ['status' => 'skipped', 'message' => "NFO has no imported NZB release ({$state})"];
            }

            if (! in_array($state, [
                NzbUploadManifestService::STATE_NZB_IMPORTED,
                NzbUploadManifestService::STATE_NFO_FAILED,
            ], true)) {
                return ['status' => 'skipped', 'message' => "NFO is waiting for NZB import ({$state})"];
            }

            if (! is_array($manifest['nfo'] ?? null)) {
                return ['status' => 'skipped', 'message' => 'Upload has no paired NFO'];
            }

            if (($manifest['nfo_failed_relinquished_attempt'] ?? null) === ($manifest['nfo_capture']['attempt'] ?? false)) {
                return ['status' => 'skipped', 'message' => 'Captured failed companion already removed'];
            }

            if (! $this->filesystem->isFile(dirname($manifestPath).DIRECTORY_SEPARATOR.$manifest['nfo']['filename'])) {
                throw new RuntimeException('Companion input is missing');
            }

            $identity = self::normalizeManifestEntry($manifest);
            if ($identity === null) {
                throw new RuntimeException('Upload manifest has no valid release identity');
            }

            $releaseId = $identity['release_id'];
            $releaseGuid = $identity['release_guid'];
            $releaseExists = Release::query()
                ->whereKey($releaseId)
                ->where('guid', $releaseGuid)
                ->exists();
            if (! $releaseExists) {
                throw new RuntimeException('The release recorded by the upload manifest no longer exists');
            }

            $this->manifests->consumeCompanion($manifestPath,
                $this->nfoService, $delete);

            Log::channel('nzb_upload')->info('Paired NFO imported', [
                'upload_id' => $manifest['upload_id'],
                'release_id' => $releaseId,
                'release_guid' => $releaseGuid,
                'filename' => $manifest['nfo']['filename'],
            ]);

            return ['status' => 'imported', 'message' => "Imported {$manifest['nfo']['filename']}"];
        } catch (Throwable $exception) {
            if ($deleteFailed && $exception instanceof NfoPayloadRejected) {
                try {
                    $this->manifests->deleteTerminalCompanion($manifestPath);
                } catch (Throwable) {
                    // Failed cleanup keeps its exact pending operation for reconciliation.
                }
            }
            Log::channel('nzb_upload')->warning('Paired NFO import failed', [
                'manifest' => $manifestPath,
                'error' => $exception->getMessage(),
            ]);

            return ['status' => 'failed', 'message' => $exception->getMessage()];
        }
    }

    /**
     * Default for the Wave 41.2 toggle only; it stays false because the paired benchmark in
     * tests/Unit/RustNzbSyncNfoImportCoverageCandidateW41ParityTest.php is below 1.5x.
     */
    public const bool W412_RUST_FFI_DEFAULT = false;

    private static ?bool $useW412RustFfi = null;

    public static function useW412RustFfi(bool $enable = true): void
    {
        self::$useW412RustFfi = $enable;
    }

    public static function usesW412RustFfi(): bool
    {
        return self::$useW412RustFfi ?? self::W412_RUST_FFI_DEFAULT;
    }

    public static function resetW412RustFfi(): void
    {
        self::$useW412RustFfi = null;
    }

    /**
     * Summarize NFO import payloads (`guid`, `nfo`). Each entry lands in the first bucket that applies: missing_guid
     * (validGuidPhp() rejects the guid), empty (no string payload or ''), oversized (longer than max(0, $maxBytes)),
     * binary (contains a NUL byte) or accepted, whose byte lengths are summed.
     *
     * @param  array<array-key, mixed>  $entries
     * @return array{entries: int, accepted: int, missing_guid: int, empty: int, oversized: int, binary: int, accepted_bytes: int}
     */
    public static function summarizeNfoImportPayloads(array $entries, int $maxBytes = 65535): array
    {
        return (self::usesW412RustFfi() ? self::summarizeNfoImportPayloadsNative($entries, $maxBytes) : null)
            ?? self::summarizeNfoImportPayloadsPhp($entries, $maxBytes);
    }

    /**
     * @param  array<array-key, mixed>  $entries
     * @return array{entries: int, accepted: int, missing_guid: int, empty: int, oversized: int, binary: int, accepted_bytes: int}
     */
    public static function summarizeNfoImportPayloadsPhp(array $entries, int $maxBytes = 65535): array
    {
        $limit = max(0, $maxBytes);
        $accepted = $missing = $empty = $oversized = $binary = $bytes = 0;
        foreach ($entries as $entry) {
            [$guid, $nfo] = self::nfoPayloadFields($entry);
            if (! $guid) {
                $missing++;
            } elseif ($nfo === null || $nfo === '') {
                $empty++;
            } elseif (\strlen($nfo) > $limit) {
                $oversized++;
            } elseif (str_contains($nfo, "\0")) {
                $binary++;
            } else {
                $accepted++;
                $bytes += \strlen($nfo);
            }
        }

        return ['entries' => \count($entries), 'accepted' => $accepted, 'missing_guid' => $missing, 'empty' => $empty, 'oversized' => $oversized,
            'binary' => $binary, 'accepted_bytes' => $bytes];
    }

    /**
     * @param  array<array-key, mixed>  $entries
     * @return array{entries: int, accepted: int, missing_guid: int, empty: int, oversized: int, binary: int, accepted_bytes: int}|null
     */
    public static function summarizeNfoImportPayloadsNative(array $entries, int $maxBytes = 65535): ?array
    {
        $words = [$maxBytes, \count($entries)];
        $texts = [];
        $size = 16 + \count($entries) * 16;
        foreach ($entries as $entry) {
            [$guid, $nfo] = self::nfoPayloadFields($entry);
            $words[] = $nfo === null ? -1 : \strlen($nfo);
            $words[] = (int) $guid;
            if ($nfo !== null) {
                $texts[] = $nfo;
                $size += \strlen($nfo);
            }
            if ($size > DirectorySyncBridge::W412_MAX_INPUT) {
                return null;
            }
        }
        $slots = DirectorySyncBridge::evaluateW412Native(1, pack('q*', ...$words).implode('', $texts), 7);
        if ($slots === null) {
            return null;
        }
        [$count, $accepted, $missing, $empty, $oversized, $binary, $bytes] = $slots;
        if ($count !== \count($entries) || min($slots) < 0 || $accepted + $missing + $empty + $oversized + $binary !== $count) {
            return null;
        }

        return ['entries' => $count, 'accepted' => $accepted, 'missing_guid' => $missing, 'empty' => $empty, 'oversized' => $oversized,
            'binary' => $binary, 'accepted_bytes' => $bytes];
    }

    /** @return array{bool, string|null} whether the guid is valid, and the NFO payload when it is a string */
    private static function nfoPayloadFields(mixed $entry): array
    {
        if (! \is_array($entry)) {
            return [false, null];
        }
        $nfo = $entry['nfo'] ?? null;

        return [self::validGuidPhp($entry['guid'] ?? null), \is_string($nfo) ? $nfo : null];
    }
}
