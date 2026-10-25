<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\Par2RenameOutcome;
use App\Models\Category;
use App\Models\Release;
use App\Models\ReleaseFile;
use App\Models\UsenetGroup;
use App\Services\NameFixing\NameFixingService;
use App\Services\NNTP\NNTPService;
use App\Support\Par2DescriptorEvidence;
use App\Support\Par2FileHashEvidence;
use App\Support\RustPipelineLibrary;
use dariusiii\rarinfo\Par2Info;
use FFI;
use FFI\CData;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Service responsible for parsing PAR2 data and applying results to releases.
 */
class Par2Processor
{
    public const string FFI_CDEF = <<<'C'
        int32_t nntmux_par2_set_fits_payload_budget(const char *sizes, size_t len, size_t count, int64_t max_payload_bytes);
        C;

    private static ?FFI $ffi = null;

    private static bool $ffiResolved = false;

    private static bool $useRustFfi = true;

    private NameFixingService $nameFixingService;

    private Par2Info $par2Info;

    private bool $addPar2;

    private bool $alternateNNTP;

    private bool $existingNameConfirmed = false;

    public function __construct(NameFixingService $nameFixingService, Par2Info $par2Info, bool $addPar2, bool $alternateNNTP)
    {
        $this->nameFixingService = $nameFixingService;
        $this->par2Info = $par2Info;
        $this->addPar2 = $addPar2;
        $this->alternateNNTP = $alternateNNTP;
    }

    /**
     * Attempt to get a better name from a PAR2 file and categorize the release.
     *
     * @param  string  $messageID  MessageID from NZB file.
     * @param  int  $relID  ID of the release.
     * @param  int  $groupID  Group ID of the release.
     * @param  NNTPService  $nntp  Class NNTPService
     * @param  int  $show  Only show result or apply it.
     */
    public function parseFromMessage(string $messageID, int $relID, int $groupID, NNTPService $nntp, int $show): bool
    {
        $outcome = $this->parseForRename($messageID, $relID, $groupID, $nntp, $show);

        return $outcome === Par2RenameOutcome::Renamed || $this->existingNameConfirmed;
    }

    /**
     * @param  null|callable(): bool  $canPersist  Checked after receipt and before writes; side effects must be idempotent.
     */
    public function parseForRename(string $messageID, int $relID, int $groupID, NNTPService $nntp, int $show, ?callable $canPersist = null, bool $expectedPar2 = false, ?callable $onRead = null): Par2RenameOutcome
    {
        $this->existingNameConfirmed = false;
        $this->nameFixingService->reset();
        if ($messageID === '') {
            return Par2RenameOutcome::Retryable;
        }

        $query = Release::query()
            ->where(['isrenamed' => 0, 'id' => $relID])
            ->select(['*', 'id as releases_id'])
            ->first();

        if ($query === null) {
            return Par2RenameOutcome::NoMatch;
        }

        // Get the PAR2 file.
        $par2 = $nntp->getMessages(UsenetGroup::getNameByID($groupID), $messageID, $this->alternateNNTP, $onRead);
        if (NNTPService::isError($par2) || ! is_string($par2) || $par2 === '') {
            return Par2RenameOutcome::Retryable;
        }

        if ($canPersist !== null && ! $canPersist()) {
            return Par2RenameOutcome::Deferred;
        }

        // Put the PAR2 into Par2Info, check if there's an error.
        $this->par2Info->setData($par2);
        if ($canPersist !== null && ! $canPersist()) {
            return Par2RenameOutcome::Deferred;
        }
        if ($this->par2Info->error) {
            return $expectedPar2 || str_contains($par2, Par2Info::PACKET_MARKER)
                ? Par2RenameOutcome::Retryable : Par2RenameOutcome::NoMatch;
        }

        // Get the file list from Par2Info.
        $files = (new Par2FileList)->getFiles($this->par2Info);
        if ($canPersist !== null && ! $canPersist()) {
            return Par2RenameOutcome::Deferred;
        }
        $metadataComplete = ! (new Par2FileList)->hasUnresolvedDescriptors($this->par2Info);
        $maxPayloadBytes = self::resolveMaxPayloadBytes($query);
        if (! self::setFitsPayloadBudget($files, $maxPayloadBytes)) {
            return Par2RenameOutcome::NoMatch;
        }
        if ($maxPayloadBytes !== null && ! $metadataComplete) {
            return Par2RenameOutcome::Retryable;
        }

        return $this->applyMetadataFiles($query, $files, $show, $canPersist, $metadataComplete);
    }

    /**
     * Apply validated, recovery-set isolated metadata without fetching another article.
     *
     * @param  null|callable(): bool  $canPersist
     * @param  array{id: int, guid: string, name: ?string, searchname: ?string, max_payload_bytes?: int}|null  $expectedSnapshot
     */
    public function applyInventoryForRename(Par2PacketAccumulator $inventory, int $relID, int $groupID, int $show, ?callable $canPersist = null, bool $allowTerminalMetadata = true, ?array $expectedSnapshot = null): Par2RenameOutcome
    {
        $this->existingNameConfirmed = false;
        $this->nameFixingService->reset();
        if ($canPersist !== null && ! $canPersist()) {
            return Par2RenameOutcome::Deferred;
        }
        $builder = Release::query()->where(['isrenamed' => 0, 'predb_id' => 0, 'id' => $relID, 'groups_id' => $groupID])->where('proc_par2', '<>', 1);
        if ($expectedSnapshot !== null) {
            if ($expectedSnapshot['id'] !== $relID) {
                return Par2RenameOutcome::Deferred;
            }
            foreach (['guid', 'name', 'searchname'] as $column) {
                $value = $expectedSnapshot[$column];
                if ($value === null) {
                    $builder->whereNull($column);
                } else {
                    $builder->whereRaw('HEX('.$column.') = ?', [strtoupper(bin2hex($value))]);
                }
            }
        }
        $query = $builder->select(['*', 'id as releases_id'])->first();
        if ($query === null) {
            return $expectedSnapshot === null ? Par2RenameOutcome::NoMatch : Par2RenameOutcome::Deferred;
        }
        $maxPayloadBytes = self::resolveMaxPayloadBytes($query, $expectedSnapshot);
        $streamComplete = $allowTerminalMetadata && $inventory->isComplete();
        $complete = $allowTerminalMetadata && (
            $inventory->isComplete()
            || $inventory->hasCompleteMainInventory()
            || ($maxPayloadBytes !== null && $maxPayloadBytes <= 0)
            || self::exceedsPayloadBudget($inventory, $maxPayloadBytes)
        );
        $files = [];
        $evidence = [];
        $parsers = $maxPayloadBytes !== null ? $inventory->parsersBySet() : [];
        foreach ($inventory->filesBySet() as $set => $setFiles) {
            if (! self::setFitsPayloadBudget($setFiles, $maxPayloadBytes)) {
                continue;
            }
            if ($maxPayloadBytes !== null && ! self::setHasCompleteDescriptors($parsers[$set] ?? null, $streamComplete)) {
                continue;
            }
            foreach ($setFiles as $file) {
                $evidence[] = $file;
                if (isset($file['name'])) {
                    $files['file:'.$file['name']] ??= $file;
                }
            }
        }
        $aborted = new RuntimeException('PAR2 inventory ownership or release snapshot changed.');
        try {
            return DB::transaction(function () use ($query, $files, $evidence, $inventory, $complete, $show, $canPersist, $expectedSnapshot, $aborted): Par2RenameOutcome {
                $this->assertInventorySnapshot($query, $canPersist, $aborted, $expectedSnapshot);
                if (! Par2DescriptorEvidence::remember($inventory,
                    ['id' => (int) $query->id, 'guid' => (string) $query->guid, 'name' => $query->name,
                        'searchname' => $query->searchname, 'predb_id' => 0, 'isrenamed' => 0], $canPersist)) {
                    throw $aborted;
                }
                $this->assertInventorySnapshot($query, $canPersist, $aborted, $expectedSnapshot);
                foreach ($evidence as $file) {
                    if (! isset($file['hash'], $file['hash_16K'])) {
                        continue;
                    }
                    $this->assertInventorySnapshot($query, $canPersist, $aborted, $expectedSnapshot);
                    if (! Par2FileHashEvidence::remember((int) $query->id, $file['hash'], $file['hash_16K'],
                        $file['size_decimal'] ?? $file['size'] ?? null,
                        ['id' => (int) $query->id, 'guid' => (string) $query->guid, 'name' => $query->name,
                            'searchname' => $query->searchname, 'predb_id' => 0, 'isrenamed' => 0], $canPersist)) {
                        throw $aborted;
                    }
                    $this->assertInventorySnapshot($query, $canPersist, $aborted, $expectedSnapshot);
                }
                $hashEvidenceComplete = $complete && $inventory->hasCompleteMainInventory() && $evidence !== [];
                foreach ($evidence as $file) {
                    if (! isset($file['hash'], $file['hash_16K'])) {
                        $hashEvidenceComplete = false;
                        break;
                    }
                }
                $hashEvidenceComplete = $hashEvidenceComplete && Par2FileHashEvidence::inventoryEvidenceMatches(
                    (int) $query->id, (string) $query->guid, $evidence,
                );
                if ($hashEvidenceComplete && ! Par2FileHashEvidence::markInventoryComplete(
                    (int) $query->id,
                    ['id' => (int) $query->id, 'guid' => (string) $query->guid, 'name' => $query->name,
                        'searchname' => $query->searchname, 'predb_id' => 0, 'isrenamed' => 0],
                    $canPersist,
                )) {
                    throw $aborted;
                }
                if ($this->addPar2) {
                    $createdTime = $query['postdate'] !== null ? Carbon::createFromFormat('Y-m-d H:i:s', $query['postdate']) : now();
                    foreach (array_slice($files, 0, 21) as $file) {
                        $this->assertInventorySnapshot($query, $canPersist, $aborted, $expectedSnapshot);
                        $added = ReleaseFile::addReleaseFiles($query->id, $file['name'], $file['size_decimal'] ?? $file['size'] ?? 0,
                            $createdTime, 0, $file['hash_16K'] ?? '');
                        $this->assertInventorySnapshot($query, $canPersist, $aborted, $expectedSnapshot);
                        if ($added > 0) {
                            Release::whereId($query->id)->increment('rarinnerfilecount', $added);
                        }
                        $this->assertInventorySnapshot($query, $canPersist, $aborted, $expectedSnapshot);
                    }
                } elseif ($complete && $files !== []) {
                    $this->assertInventorySnapshot($query, $canPersist, $aborted, $expectedSnapshot);
                    Release::whereId($query->id)->where('rarinnerfilecount', '<', count($files))
                        ->update(['rarinnerfilecount' => count($files)]);
                    $this->assertInventorySnapshot($query, $canPersist, $aborted, $expectedSnapshot);
                }

                if (\in_array((int) $query['categories_id'], Category::OTHERS_GROUP, true)) {
                    foreach ($files as $file) {
                        $this->assertInventorySnapshot($query, $canPersist, $aborted, $expectedSnapshot);
                        $query['textstring'] = $file['name'];
                        if ($this->nameFixingService->checkName($query, true, 'PAR2, ', true, (bool) $show, markProcessed: false)) {
                            if ($canPersist !== null && ! $canPersist()) {
                                throw $aborted;
                            }

                            return Par2RenameOutcome::Renamed;
                        }
                    }
                } else {
                    $this->existingNameConfirmed = $files !== [];
                }
                $this->assertInventorySnapshot($query, $canPersist, $aborted, $expectedSnapshot);

                return $complete ? Par2RenameOutcome::NoMatch : Par2RenameOutcome::Retryable;
            });
        } catch (Throwable $error) {
            if ($error === $aborted) {
                return Par2RenameOutcome::Deferred;
            }

            throw $error;
        }
    }

    /**
     * Hold the release row lock through metadata and naming writes. Compare bytes in PHP so
     * database collations cannot treat changed case or accents as the original identity.
     *
     * @param  null|callable(): bool  $canPersist
     * @param  array<string, mixed>|null  $expectedSnapshot
     *
     * @phpstan-impure
     */
    private function assertInventorySnapshot(Release $expected, ?callable $canPersist, RuntimeException $aborted, ?array $expectedSnapshot = null): void
    {
        if ($canPersist !== null && ! $canPersist()) {
            throw $aborted;
        }
        $current = Release::query()->where('id', $expected->id)->lockForUpdate()->first();
        if ($current === null || $current->guid !== $expected->guid || $current->name !== $expected->name
            || $current->searchname !== $expected->searchname || (int) $current->groups_id !== (int) $expected->groups_id
            || (int) $current->predb_id !== 0 || (int) $current->isrenamed !== 0 || (int) $current->proc_par2 === 1
            || self::resolveMaxPayloadBytes($current, $expectedSnapshot) !== self::resolveMaxPayloadBytes($expected, $expectedSnapshot)) {
            throw $aborted;
        }
    }

    /**
     * Whether a PAR2 candidate has already proved it cannot describe this release's payload, so
     * further articles from the same PAR2 stream need not be downloaded.
     */
    public static function exceedsPayloadBudget(Par2PacketAccumulator $inventory, ?int $maxPayloadBytes): bool
    {
        if ($maxPayloadBytes === null) {
            return false;
        }
        $state = $inventory->exportState();
        if ($maxPayloadBytes <= 0) {
            return $state['packets'] !== [] || $state['skip_remaining'] > 0 || $state['tail'] !== '';
        }
        $sets = $inventory->filesBySet();
        if ($sets === []) {
            return false;
        }
        $hasResolvedDescriptor = false;
        foreach ($sets as $setFiles) {
            foreach ($setFiles as $file) {
                if (isset($file['name']) || isset($file['size']) || isset($file['size_decimal'])) {
                    $hasResolvedDescriptor = true;
                    break;
                }
            }
            if (self::setFitsPayloadBudget($setFiles, $maxPayloadBytes)) {
                return false;
            }
        }

        return $hasResolvedDescriptor;
    }

    /**
     * A PAR2 recovery set can only describe a release whose non-PAR2 payload can hold its files.
     *
     * @param  array<string|int, array{name?: string, size?: int|float, size_decimal?: string, hash?: string, hash_16K?: string, blocks?: int, next_offset?: int}>  $setFiles
     */
    public static function setFitsPayloadBudget(array $setFiles, ?int $maxPayloadBytes): bool
    {
        // Retains the native PHP path: building the FFI input costs more than the check itself
        // (0.56x mean / 0.55x p99 common, 0.28x / 0.29x uncommon); see setFitsPayloadBudgetNative().
        return self::setFitsPayloadBudgetPhp($setFiles, $maxPayloadBytes);
    }

    /**
     * @param  array<string|int, array{name?: string, size?: int|float, size_decimal?: string, hash?: string, hash_16K?: string, blocks?: int, next_offset?: int}>  $setFiles
     */
    public static function setFitsPayloadBudgetPhp(array $setFiles, ?int $maxPayloadBytes): bool
    {
        if ($maxPayloadBytes === null) {
            return true;
        }
        if ($maxPayloadBytes <= 0) {
            return false;
        }
        $remaining = $maxPayloadBytes;
        $maxIntDecimal = (string) PHP_INT_MAX;
        foreach ($setFiles as $file) {
            $rawDecimal = $file['size_decimal'] ?? null;
            $rawSize = $file['size'] ?? null;
            if ($rawDecimal === null && $rawSize === null && ! isset($file['name'])) {
                continue;
            }
            if (is_int($rawSize) && $rawSize >= 0 && ($rawDecimal === null || $rawDecimal === (string) $rawSize)) {
                $fileBytes = $rawSize;
            } else {
                $sizeDecimal = Par2FileHashEvidence::sizeDecimal($rawDecimal ?? $rawSize);
                if ($sizeDecimal === null) {
                    if (! is_float($rawSize) || ! is_finite($rawSize) || $rawSize < 0.0 || $rawSize > (float) $remaining) {
                        return false;
                    }
                    $sizeDecimal = number_format($rawSize, 0, '', '');
                }
                $len = strlen($sizeDecimal);
                if ($len > 19 || ($len === 19 && strcmp($sizeDecimal, $maxIntDecimal) > 0)) {
                    return false;
                }
                $fileBytes = (int) $sizeDecimal;
            }
            if ($fileBytes > $remaining) {
                return false;
            }
            $remaining -= $fileBytes;
        }

        return true;
    }

    /**
     * Rust budget check over newline-joined decimal candidates; float sizes decline to PHP.
     *
     * @param  array<string|int, array{name?: string, size?: int|float, size_decimal?: string, hash?: string, hash_16K?: string, blocks?: int, next_offset?: int}>  $setFiles
     */
    public static function setFitsPayloadBudgetNative(array $setFiles, ?int $maxPayloadBytes, bool &$supported = false): bool
    {
        $supported = false;
        if ($maxPayloadBytes === null || $maxPayloadBytes <= 0) {
            $supported = true;

            return $maxPayloadBytes === null;
        }
        $ffi = self::rustFfi();
        if ($ffi === null) {
            return self::setFitsPayloadBudgetPhp($setFiles, $maxPayloadBytes);
        }
        $sizes = [];
        foreach ($setFiles as $file) {
            $rawDecimal = $file['size_decimal'] ?? null;
            $rawSize = $file['size'] ?? null;
            if ($rawDecimal === null && $rawSize === null && ! isset($file['name'])) {
                continue;
            }
            if (is_int($rawSize) && $rawSize >= 0 && ($rawDecimal === null || $rawDecimal === (string) $rawSize)) {
                $sizes[] = $rawSize;

                continue;
            }
            if (is_float($rawSize)) {
                return self::setFitsPayloadBudgetPhp($setFiles, $maxPayloadBytes);
            }
            $candidate = $rawDecimal ?? $rawSize;
            if (! is_string($candidate) && (! is_int($candidate) || $candidate < 0)) {
                $supported = true;

                return false;
            }
            $sizes[] = $candidate;
        }
        $joined = implode("\n", $sizes);
        try {
            // @phpstan-ignore method.notFound
            $fits = $ffi->nntmux_par2_set_fits_payload_budget($joined, strlen($joined), count($sizes), $maxPayloadBytes);
        } catch (Throwable) {
            return self::setFitsPayloadBudgetPhp($setFiles, $maxPayloadBytes);
        }
        if ($fits !== 0 && $fits !== 1) {
            return self::setFitsPayloadBudgetPhp($setFiles, $maxPayloadBytes);
        }
        $supported = true;

        return $fits === 1;
    }

    /**
     * Toggle the Rust helpers; disabled, every *Native method falls back to PHP.
     */
    public static function useRustFfi(bool $use): void
    {
        self::$useRustFfi = $use;
    }

    private static function rustFfi(): ?FFI
    {
        if (! self::$useRustFfi) {
            return null;
        }
        if (! self::$ffiResolved) {
            self::$ffiResolved = true;
            self::$ffi = RustPipelineLibrary::resolveDomain('additional', self::FFI_CDEF);
        }

        return self::$ffi;
    }

    private static function setHasCompleteDescriptors(?Par2Info $parser, bool $streamComplete): bool
    {
        if ($parser === null || (new Par2FileList)->hasUnresolvedDescriptors($parser)) {
            return false;
        }
        if ($streamComplete) {
            return true;
        }
        foreach ($parser->getPackets(true) ?: [] as $packet) {
            if (($packet['head_type'] ?? null) === Par2Info::PACKET_MAIN) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, mixed>|null $expectedSnapshot */
    private static function resolveMaxPayloadBytes(object $release, ?array $expectedSnapshot = null): ?int
    {
        $releaseSize = (int) ($release->size ?? 0);
        if ($releaseSize <= 0) {
            return isset($expectedSnapshot['max_payload_bytes'])
                ? max(0, (int) $expectedSnapshot['max_payload_bytes'])
                : null;
        }
        if (isset($expectedSnapshot['max_payload_bytes'])) {
            return min($releaseSize, max(0, (int) $expectedSnapshot['max_payload_bytes']));
        }

        return $releaseSize;
    }

    /**
     * @param  array<string, array{name?: string, size?: int|float, size_decimal?: string, hash?: string, hash_16K?: string, blocks?: int, next_offset?: int}>  $files
     * @param  null|callable(): bool  $canPersist
     */
    private function applyMetadataFiles(Release $query, array $files, int $show, ?callable $canPersist, bool $metadataComplete, bool $countUncollected = true): Par2RenameOutcome
    {
        $relID = (int) $query->releases_id;
        $attemptRename = \in_array((int) $query['categories_id'], Category::OTHERS_GROUP, true);
        $foundName = ! $attemptRename;
        if (! array_filter($files, static fn (array $file): bool => isset($file['name']))) {
            return Par2RenameOutcome::Retryable;
        }
        if (\count($files) > 0) {
            $filesAdded = 0;

            // Loop through the files.
            foreach ($files as $file) {
                if (! isset($file['name'])) {
                    continue;
                }

                // If we found a name and added 20 files, stop.
                if ($foundName === true && $filesAdded > 20) {
                    break;
                }

                if ($canPersist !== null && ! $canPersist()) {
                    return Par2RenameOutcome::Deferred;
                }
                if ($this->addPar2) {
                    // Add to release files.
                    if ($filesAdded < 21) {
                        $added = $this->persistPar2File(
                            $relID,
                            $file,
                            $query['postdate'] !== null ? Carbon::createFromFormat('Y-m-d H:i:s', $query['postdate']) : now(),
                            $canPersist
                        );
                        if ($added === null) {
                            return Par2RenameOutcome::Deferred;
                        }
                        $filesAdded += $added;
                    }
                } else {
                    $filesAdded++;
                }

                // Try to get a new name.
                if ($foundName === false) {
                    if ($canPersist !== null && ! $canPersist()) {
                        return Par2RenameOutcome::Deferred;
                    }
                    $query['textstring'] = $file['name'];
                    if ($this->nameFixingService->checkName($query, true, 'PAR2, ', true, (bool) $show, markProcessed: false)) {
                        $foundName = true;
                    }
                }
            }

            // If we found some files.
            if (! $this->addPar2 && $countUncollected && $filesAdded > 0) {
                if ($canPersist !== null && ! $canPersist()) {
                    return Par2RenameOutcome::Deferred;
                }
                // Update the file count with the new file count + old file count.
                Release::whereId($relID)->increment('rarinnerfilecount', $filesAdded);
            }
            if ($foundName === true) {
                if (! $attemptRename) {
                    $this->existingNameConfirmed = true;

                    return Par2RenameOutcome::NoMatch;
                }

                return Par2RenameOutcome::Renamed;
            }
        }

        return $metadataComplete ? Par2RenameOutcome::NoMatch : Par2RenameOutcome::Retryable;
    }

    /**
     * Commit metadata, hash enrichment and the new-row count together while the claim is valid.
     *
     * @param  array{name: string, size?: int|float, size_decimal?: string, hash?: string, hash_16K?: string, blocks?: int, next_offset?: int}  $file
     * @param  null|callable(): bool  $canPersist
     */
    private function persistPar2File(int $releaseId, array $file, mixed $createdTime, ?callable $canPersist): ?int
    {
        $transactionLevel = DB::transactionLevel();
        DB::beginTransaction();
        try {
            if ($canPersist !== null && ! $canPersist()) {
                DB::rollBack($transactionLevel);

                return null;
            }
            $added = ReleaseFile::addReleaseFiles($releaseId, $file['name'], $file['size'] ?? 0,
                $createdTime, 0, $file['hash_16K'] ?? '');
            if ($canPersist !== null && ! $canPersist()) {
                DB::rollBack($transactionLevel);

                return null;
            }
            if ($added > 0) {
                Release::whereId($releaseId)->increment('rarinnerfilecount', $added);
            }
            if ($canPersist !== null && ! $canPersist()) {
                DB::rollBack($transactionLevel);

                return null;
            }
            DB::commit();

            return $added;
        } catch (Throwable $error) {
            if (DB::transactionLevel() > $transactionLevel) {
                DB::rollBack($transactionLevel);
            }

            throw $error;
        }
    }

    /** Disabled: common p99 missed 1.5x in one of five paired runs (1.58x, 1.65x, 1.29x, 1.68x, 2.20x; see the W42 parity test). */
    public const bool W424_RUST_FFI_DEFAULT = false;

    public const string W424_FFI_CDEF = <<<'C'
        ssize_t nntmux_w424_par2_rename_batch(const char *input, size_t len, char *out, size_t cap);
        C;

    private const array W424_SUMMARY_KEYS = ['total_files', 'empty_name_count', 'hex_hash_name_count', 'obfuscated_name_count', 'release_like_count', 'par2_volume_count', 'executable_count'];

    private const array W424_EXECUTABLE_EXTENSIONS = ['exe' => true, 'bat' => true, 'cmd' => true, 'scr' => true, 'com' => true, 'vbs' => true, 'js' => true, 'ps1' => true, 'msi' => true, 'lnk' => true];

    private static ?bool $w424UseRustFfi = null;

    private static FFI|false|null $w424Ffi = null;

    public static function useW424RustFfi(bool $enable = true): void
    {
        self::$w424UseRustFfi = $enable;
    }

    public static function usesW424RustFfi(): bool
    {
        return self::$w424UseRustFfi ?? self::W424_RUST_FFI_DEFAULT;
    }

    public static function resetW424RustFfi(): void
    {
        self::$w424UseRustFfi = null;
        self::$w424Ffi = null;
    }

    /**
     * Summarize PAR2 rename candidates by base name (after the last slash)
     * and stem (before its last dot): 32/40 hex stems, 16+ alphanumeric
     * stems, dotted release stems with a dash, .volN+M.par2 volumes and
     * executable extensions.
     * Entries come from strings, integers, or the filename or name array
     * field; other values (including objects) count as empty entries.
     *
     * @param  array<array-key, mixed>  $entries
     * @return array<string, int>
     */
    public static function summarizePar2RenameCandidateBatch(array $entries): array
    {
        return self::usesW424RustFfi()
            ? self::summarizePar2RenameCandidateBatchNative($entries)
            : self::summarizePar2RenameCandidateBatchPhp($entries);
    }

    /**
     * Rust summary; falls back to PHP when the library or input frame is unusable.
     *
     * @param  array<array-key, mixed>  $entries
     * @return array<string, int>
     */
    public static function summarizePar2RenameCandidateBatchNative(array $entries): array
    {
        return self::w424Call(self::w424Entries($entries)) ?? self::summarizePar2RenameCandidateBatchPhp($entries);
    }

    /**
     * @param  array<array-key, mixed>  $entries
     * @return array<string, int>
     */
    public static function summarizePar2RenameCandidateBatchPhp(array $entries): array
    {
        $summary = array_fill_keys(self::W424_SUMMARY_KEYS, 0);
        foreach (self::w424Entries($entries) as $entry) {
            $summary['total_files']++;
            $entry = trim($entry);
            if ($entry === '') {
                $summary['empty_name_count']++;

                continue;
            }
            $slash = strrpos($entry, '/');
            $backslash = strrpos($entry, '\\');
            $base = substr($entry, max($slash === false ? -1 : $slash, $backslash === false ? -1 : $backslash) + 1);
            $dot = strrpos($base, '.');
            $stem = $dot === false ? $base : substr($base, 0, $dot);
            $extension = $dot === false ? '' : strtolower(substr($base, $dot + 1));
            $summary['hex_hash_name_count'] += (int) (preg_match('/^(?:[0-9A-Fa-f]{32}|[0-9A-Fa-f]{40})\z/', $stem) === 1);
            $summary['obfuscated_name_count'] += (int) (preg_match('/^[A-Za-z0-9]{16,}\z/', $stem) === 1);
            $summary['release_like_count'] += (int) (substr_count($stem, '.') >= 2 && str_contains($stem, '-'));
            $summary['par2_volume_count'] += (int) (preg_match('/\.vol\d+\+\d+\.par2\z/i', $entry) === 1);
            $summary['executable_count'] += (int) isset(self::W424_EXECUTABLE_EXTENSIONS[$extension]);
        }

        return $summary;
    }

    /** Only integer and string entries participate; objects are never cast or serialized.
     *
     * @param  array<array-key, mixed>  $input
     * @return list<string>
     */
    private static function w424Entries(array $input): array
    {
        $entries = [];
        foreach ($input as $item) {
            if (is_array($item)) {
                $item = $item['filename'] ?? $item['name'] ?? null;
            }
            $entries[] = is_string($item) || is_int($item) ? (string) $item : '';
        }

        return $entries;
    }

    /** @param list<string> $entries
     * @return array<string, int>|null
     */
    private static function w424Call(array $entries): ?array
    {
        try {
            $input = serialize([$entries]);
            if (strlen($input) > 16777216) {
                return null;
            }
            self::$w424Ffi ??= RustPipelineLibrary::resolveDomain('metadata_w424', self::W424_FFI_CDEF) ?? false;
            $ffi = self::$w424Ffi;
            if ($ffi === false) {
                return null;
            }
            $capacity = 0;
            $out = RustPipelineLibrary::obtainScratch($ffi, 2048, $capacity);
            if ($out === null) {
                return null;
            }
            // @phpstan-ignore method.notFound (Declared by W424_FFI_CDEF.)
            $written = $ffi->nntmux_w424_par2_rename_batch($input, strlen($input), $out, $capacity);
            if (is_int($written) && $written < -1 && $written >= -16777216) {
                $out = RustPipelineLibrary::obtainScratch($ffi, -$written, $capacity);
                if ($out === null) {
                    return null;
                }
                // @phpstan-ignore method.notFound (Declared by W424_FFI_CDEF.)
                $written = $ffi->nntmux_w424_par2_rename_batch($input, strlen($input), $out, $capacity);
            }
            if (! is_int($written) || $written <= 0 || $written > $capacity || $written > 16777216) {
                return null;
            }
            $result = @unserialize(FFI::string($out, $written), ['allowed_classes' => false]);
            if (! is_array($result) || array_keys($result) !== self::W424_SUMMARY_KEYS || $result['total_files'] !== count($entries)) {
                return null;
            }
            $summary = [];
            foreach (self::W424_SUMMARY_KEYS as $key) {
                $value = $result[$key];
                if (! is_int($value) || $value < 0 || $value > count($entries)) {
                    return null;
                }
                $summary[$key] = $value;
            }

            return $summary;
        } catch (Throwable) {
            return null;
        }
    }

    // ---------------------------------------------------------------------
    // Wave 51.4 batch helper
    // ---------------------------------------------------------------------

    /** Enabled: minimum of three paired runs cleared every 1.5x gate (common mean 3.61x, p99 2.95x; uncommon mean 3.44x, p99 3.09x); see RustIsbnOpenLibPar2ListProcessorNfoW51ParityTest. */
    private const bool W51_RUST_FFI_DEFAULT = true;

    public const string W51_FFI_CDEF = <<<'C'
        ssize_t nntmux_w514_par2_rename_candidate_batch(const char *text, size_t text_len, const char *ints, size_t ints_len, size_t count, int64_t *out, size_t out_cap);
        C;

    /** Rename candidate flags returned by renameCandidateBatch(). */
    public const int W51_EMPTY_NAME = 1;

    public const int W51_HEX_HASH = 2;

    public const int W51_OBFUSCATED = 4;

    public const int W51_RELEASE_LIKE = 8;

    public const int W51_PAR2_VOLUME = 16;

    public const int W51_EXECUTABLE = 32;

    private static ?bool $useW51RustFfi = null;

    private static FFI|false|null $w51Ffi = null;

    private static ?CData $w51Out = null;

    private static int $w51OutCap = 0;

    public static function usesW51RustFfi(): bool
    {
        return self::$useW51RustFfi ?? self::W51_RUST_FFI_DEFAULT;
    }

    /**
     * Run $callback with the W51 batch dispatch forced on or off, then restore the previous choice.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW51RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::$useW51RustFfi;
        self::$useW51RustFfi = $enabled;
        try {
            return $callback();
        } finally {
            self::$useW51RustFfi = $previous;
        }
    }

    /**
     * Classify PAR2 rename candidates, two ints each: [flags, offset << 32 | length of the
     * stem in the raw name]. The stem is the trimmed name after its last slash or backslash,
     * up to the last dot. Flags follow summarizePar2RenameCandidateBatchPhp(): 1 empty, 2
     * 32/40 hex stem, 4 16+ alphanumeric stem, 8 dotted release stem with a dash, 16
     * .volN+M.par2 volume, 32 executable extension.
     *
     * @param  list<string>  $names
     * @return list<int>
     */
    public static function renameCandidateBatch(array $names): array
    {
        return self::usesW51RustFfi()
            ? self::renameCandidateBatchNative($names)
            : self::renameCandidateBatchPhp($names);
    }

    /**
     * @param  list<string>  $names
     * @return list<int>
     */
    public static function renameCandidateBatchNative(array $names): array
    {
        self::w51AssertList($names);
        if ($names === []) {
            return [];
        }

        return self::w51Call(implode("\0", $names), '', \count($names))
            ?? self::renameCandidateBatchPhp($names);
    }

    /**
     * @param  list<string>  $names
     * @return list<int>
     */
    public static function renameCandidateBatchPhp(array $names): array
    {
        self::w51AssertList($names);
        $out = [];
        foreach ($names as $raw) {
            $entry = trim($raw);
            $offset = strspn($raw, " \t\n\r\0\x0B");
            if ($entry === '') {
                array_push($out, self::W51_EMPTY_NAME, $offset << 32);

                continue;
            }
            $slash = strrpos($entry, '/');
            $backslash = strrpos($entry, '\\');
            $baseAt = max($slash === false ? -1 : $slash, $backslash === false ? -1 : $backslash) + 1;
            $base = substr($entry, $baseAt);
            $dot = strrpos($base, '.');
            $stem = $dot === false ? $base : substr($base, 0, $dot);
            $extension = $dot === false ? '' : strtolower(substr($base, $dot + 1));
            $flags = 0;
            if (preg_match('/^(?:[0-9A-Fa-f]{32}|[0-9A-Fa-f]{40})\z/', $stem) === 1) {
                $flags |= self::W51_HEX_HASH;
            }
            if (preg_match('/^[A-Za-z0-9]{16,}\z/', $stem) === 1) {
                $flags |= self::W51_OBFUSCATED;
            }
            if (substr_count($stem, '.') >= 2 && str_contains($stem, '-')) {
                $flags |= self::W51_RELEASE_LIKE;
            }
            if (preg_match('/\.vol\d+\+\d+\.par2\z/i', $entry) === 1) {
                $flags |= self::W51_PAR2_VOLUME;
            }
            if (isset(self::W424_EXECUTABLE_EXTENSIONS[$extension])) {
                $flags |= self::W51_EXECUTABLE;
            }
            $out[] = $flags;
            $out[] = (($offset + $baseAt) << 32) | \strlen($stem);
        }

        return $out;
    }

    /**
     * One FFI crossing for the batch: NUL-joined names, two int64 words back per record. Null when
     * the library is missing or declines.
     *
     * @return list<int>|null
     */
    private static function w51Call(string $text, string $ints, int $count): ?array
    {
        try {
            self::$w51Ffi ??= RustPipelineLibrary::resolveDomain('metadata_w51', self::W51_FFI_CDEF) ?? false;
            $ffi = self::$w51Ffi;
            if ($ffi === false) {
                return null;
            }
            $need = $count * 2;
            $out = self::$w51Out;
            if ($out === null || self::$w51OutCap < $need) {
                self::$w51OutCap = max(1024, $need);
                $out = self::$w51Out = $ffi->new('int64_t['.self::$w51OutCap.']');
            }
            // @phpstan-ignore method.notFound (Declared by W51_FFI_CDEF.)
            $written = $ffi->nntmux_w514_par2_rename_candidate_batch($text, \strlen($text), $ints, \strlen($ints), $count, $out, self::$w51OutCap);
            if ($written !== $need) {
                return null;
            }
            $values = unpack('P*', FFI::string($out, $need * 8));

            return $values === false ? null : array_values($values);
        } catch (Throwable) {
            return null;
        }
    }

    /** @param array<array-key, mixed> $values */
    private static function w51AssertList(array $values): void
    {
        if (! array_is_list($values)) {
            throw new InvalidArgumentException('W51 batch input must be a list.');
        }
    }
}
