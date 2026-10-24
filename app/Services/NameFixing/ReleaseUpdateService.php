<?php

declare(strict_types=1);

namespace App\Services\NameFixing;

use App\Events\ReleaseNameFixed;
use App\Models\Category;
use App\Models\Release;
use App\Models\UsenetGroup;
use App\Services\AdditionalProcessing\AdditionalCandidateQuery;
use App\Services\AdditionalProcessing\ReleaseSearchSyncCoordinator;
use App\Services\AdditionalProcessing\State\PersistenceMetricsCollector;
use App\Services\Categorization\CategorizationService;
use App\Services\ReleaseCleaningService;
use App\Services\TvProcessing\TvProcessingCandidateQuery;
use App\Support\Par2RenameProgress;
use App\Support\PredbSearchDocument;
use App\Support\ReleaseArchiveNamingEvidence;
use App\Support\RustPipelineLibrary;
use App\Traits\OwnsPredbIdentityTransactions;
use FFI;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Service for updating releases with new names.
 *
 * Handles the actual database updates and category re-determination
 * when a release is renamed.
 */
class ReleaseUpdateService
{
    private const string FFI_CDEF = <<<'C'
        ssize_t nntmux_name_fixing_query_pass(uint32_t mode, const char *input, size_t len, int64_t first, int64_t second, char *out, size_t cap);
        C;

    // Retained PHP: the paired caller benchmark must pass all four 1.5x gates.
    private static bool $useRustFfi = false;

    public static function setUseRustFfi(bool $enabled): void
    {
        self::$useRustFfi = $enabled;
    }

    /** Negative ABI results retain the exact PHP implementation. */
    private static function queryPassNative(int $mode, ?string $input, int $first = 0, int $second = 0, int $needed = 4096): ?string
    {
        if ($input === null || strlen($input) > intdiv(PHP_INT_MAX - 4096, 8)) {
            return null;
        }
        try {
            $ffi = RustPipelineLibrary::resolveDomain('cleaning', self::FFI_CDEF);
            if ($ffi === null) {
                return null;
            }
            $capacity = 0;
            $buffer = RustPipelineLibrary::obtainScratch($ffi, max($needed, strlen($input) * 8 + 4096), $capacity);
            if ($buffer === null) {
                return null;
            }
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_name_fixing_query_pass($mode, $input, strlen($input), $first, $second, $buffer, $capacity);

            return is_int($written) && $written >= 0 && $written <= $capacity
                ? ($written === 0 ? '' : FFI::string($buffer, $written)) : null;
        } catch (Throwable) {
            return null;
        }
    }

    /** @param list<string> $fields */
    private static function queryPassFields(array $fields): ?string
    {
        $input = '';
        foreach ($fields as $field) {
            if (strlen($field) > 0xFFFFFFFF) {
                return null;
            }
            $input .= pack('V', strlen($field)).$field;
        }

        return $input;
    }

    use OwnsPredbIdentityTransactions;

    public const PREDB_IDENTITY_POLICY = '8a825706e8b83c748ff15ec520a8401384082346ca3b0e3bad795e0a66eeec56';

    /**
     * @var list<string>
     */
    private const SINGLE_UPDATE_COLUMNS = [
        'predb_id',
        'proc_nfo',
        'proc_files',
        'proc_par2',
        'proc_uid',
        'proc_hash16k',
        'proc_srr',
        'proc_crc32',
    ];

    /**
     * PreDB regex pattern for scene release names.
     */
    public const PREDB_REGEX = '/([\w.\'()\[\]-]+(?:[\s._-]+[\w.\'()\[\]-]+)+[-.][\w]+)/ui';

    // Constants for name fixing status
    public const PROC_NFO_NONE = 0;

    public const PROC_NFO_DONE = 1;

    public const PROC_FILES_NONE = 0;

    public const PROC_FILES_DONE = 1;

    public const PROC_PAR2_NONE = 0;

    public const PROC_PAR2_DONE = 1;

    public const PROC_UID_NONE = 0;

    public const PROC_UID_DONE = 1;

    public const PROC_HASH16K_NONE = 0;

    public const PROC_HASH16K_DONE = 1;

    public const PROC_SRR_NONE = 0;

    public const PROC_SRR_DONE = 1;

    public const PROC_CRC_NONE = 0;

    public const PROC_CRC_DONE = 1;

    // Constants for overall rename status
    public const IS_RENAMED_NONE = 0;

    public const IS_RENAMED_DONE = 1;

    protected CategorizationService $category;

    protected FileNameCleaner $fileNameCleaner;

    protected bool $echoOutput;

    private readonly ReleaseSearchSyncCoordinator $searchSyncCoordinator;

    /** @var array{before: array{id: int, guid: string, name: ?string, searchname: ?string}, after: array{id: int, guid: string, name: ?string, searchname: ?string}}|null */
    private ?array $committedIdentity = null;

    /** @var array{id: int, guid: string, name: ?string, searchname: ?string}|null */
    private ?array $admittedIdentity = null;

    private int $identityAttempt = 0;

    /**
     * The release ID we are trying to rename.
     */
    protected int $relid = 0;

    /**
     * Has the current release found a new name?
     */
    public bool $matched = false;

    /**
     * Was the check completed?
     */
    public bool $done = false;

    /**
     * How many releases have got a new name?
     */
    public int $fixed = 0;

    /**
     * How many releases were checked.
     */
    public int $checked = 0;

    private bool $episodeNamingDeferredByArchiveProof = false;

    public function __construct(
        ?CategorizationService $category = null,
        ?FileNameCleaner $fileNameCleaner = null,
        ?ReleaseSearchSyncCoordinator $searchSyncCoordinator = null,
    ) {
        $this->category = $category ?? new CategorizationService;
        $this->fileNameCleaner = $fileNameCleaner ?? new FileNameCleaner;
        $this->searchSyncCoordinator = $searchSyncCoordinator
            ?? new ReleaseSearchSyncCoordinator(new PersistenceMetricsCollector);
        $this->echoOutput = config('nntmux.echocli');
    }

    /**
     * Update the release with the new information.
     *
     * @param  object|array<string, mixed>  $release  The release to update
     * @param  string  $name  The new name
     * @param  string  $method  The method that found the name
     * @param  bool  $echo  Whether to actually update the database
     * @param  string  $type  The type string for logging
     * @param  bool  $nameStatus  Whether to update status columns
     * @param  bool  $show  Whether to show output
     * @param  int|null  $preId  PreDB ID if matched
     * @param  string|null  $filenameEvidence  Original filename used to accept a PreDB mapping
     *
     * @throws \Exception
     */
    public function updateRelease(
        object|array $release,
        string $name,
        string $method,
        bool $echo,
        string $type,
        bool $nameStatus,
        bool $show,
        ?int $preId = 0,
        ?string $filenameEvidence = null,
        ?callable $filenameGuard = null
    ): void {
        $this->clearIdentityAttestation();
        $this->resetArchiveNamingDeferral();
        $this->matched = $this->done = false;
        $preId = $preId ?? 0;
        if (is_array($release)) {
            $release = (object) $release;
        }
        $release = clone $release;

        // If $release does not have a releases_id, we should add it.
        if (! isset($release->releases_id)) {
            $release->releases_id = $release->id;
        }
        $expectedSnapshot = $this->completedReleaseSnapshot($release) === null ? null : clone $release;
        if ($expectedSnapshot === null && $this->hasObservedAdditionalClaim($release)) {
            return;
        }

        if ($preId > 0) {
            $this->updateConfirmedPredbRelease($release, $name, $method, $echo, $type, $nameStatus, $show, $preId, $filenameEvidence, $filenameGuard);

            return;
        }

        if ($expectedSnapshot !== null || $this->relid !== $release->releases_id) {
            $literalSceneName = $this->fileNameCleaner->isLiteralTechnicalSceneTitle($name);
            $cleanedName = $literalSceneName ? $name : (new ReleaseCleaningService)->fixerCleaner($name);
            // Normalize and sanity-check candidate for non-trusted sources
            $normalizedName = $this->fileNameCleaner->normalizeCandidateTitle($cleanedName);
            $newName = $literalSceneName ? $name : $this->fileNameCleaner->formatSearchName($cleanedName, $normalizedName);

            // Determine if the source is trusted enough to bypass plausibility checks
            $trustedSource = $this->isTrustedSource($type, $method, $preId);

            if (! $trustedSource && ! $this->fileNameCleaner->isPlausibleReleaseTitle($normalizedName)) {
                // Skip low-quality rename candidates for untrusted sources
                $this->done = true;

                return;
            }

            if (strtolower($newName) !== strtolower($release->searchname)) {
                if ($this->renamesSeasonPackToEpisode((string) $release->searchname, $newName)) {
                    $this->done = true;

                    return;
                }

                if ($type === 'PAR2, ' && ! $literalSceneName) {
                    $newName = ucwords($newName);
                    if (preg_match('/(.+?)\.[a-z0-9]{2,3}(PAR2)?$/i', $name, $hit)) {
                        $newName = $hit[1];
                    }
                }

                // Split on path separator backslash to strip any path
                $newName = explode('\\', $newName);
                $newName = preg_replace(['/^[=_.:\s-]+/', '/[=_.:\s-]+$/'], '', $newName[0]);

                $newTitle = $literalSceneName ? $newName : substr($newName, 0, 299);

                if (! $echo && ! $this->allowsArchiveNamingPreview($release, $newTitle)) {
                    return;
                }
                if ($echo && ! $this->performDatabaseUpdate($release, $newTitle, $type, $nameStatus, $preId, $expectedSnapshot, null, $filenameGuard)) {
                    $this->relid = 0;

                    return;
                }
                $this->matched = true;
                $this->relid = (int) $release->releases_id;
                $this->incrementFixed($echo, $release, $newTitle);

                $determinedCategory = null;
                if ($this->echoOutput && $show) {
                    $determinedCategory = $this->category->determineCategory(
                        $release->groups_id,
                        $newTitle,
                        ! empty($release->fromname) ? $release->fromname : ''
                    );

                    $this->echoReleaseInfo($release, $newTitle, $determinedCategory, $type, $method);
                }

            }
        }
        $this->done = true;
    }

    /** Confirmed catalogue titles keep their canonical bytes and require an unchanged release snapshot. */
    private function updateConfirmedPredbRelease(
        object $release,
        string $title,
        string $method,
        bool $update,
        string $type,
        bool $nameStatus,
        bool $show,
        int $predbId,
        ?string $filenameEvidence = null,
        ?callable $filenameGuard = null
    ): void {
        $this->reset();
        $release = clone $release;
        if (trim($title) === '' || ! mb_check_encoding($title, 'UTF-8')
            || mb_strlen($title, 'UTF-8') > 255 || preg_match('/\p{C}/u', $title) !== 0
            || $this->completedReleaseSnapshot($release) === null) {
            return;
        }
        if (! $update) {
            $allowed = $this->withPredbIdentity($release, $predbId, $title, $filenameEvidence, function (string $canonicalTitle) use ($release, $filenameEvidence, &$title): bool {
                $current = $this->releaseSnapshotQuery($release)->lockForUpdate()->first();
                if ($current === null || $this->renamesSeasonPackToEpisode((string) $current->searchname, $canonicalTitle)
                    || ! $this->allowsArchiveNamingLocked($current, $canonicalTitle, $filenameEvidence)) {
                    return false;
                }
                $title = $canonicalTitle;

                return true;
            }, $filenameGuard);
            if (! $allowed) {
                return;
            }
        }
        if (PredbSearchDocument::exactValue($title) === PredbSearchDocument::exactValue((string) $release->searchname)) {
            if ($update) {
                $this->attachPredbSnapshot($release, $predbId, $title, $filenameEvidence, $filenameGuard);
            } else {
                $this->matched = $this->done = true;
            }

            return;
        }
        if ($update && ! $this->performDatabaseUpdate($release, $title, $type, $nameStatus, $predbId, $release, $filenameEvidence, $filenameGuard)) {
            return;
        }

        $this->relid = (int) $release->releases_id;
        $this->matched = $this->done = true;
        $this->incrementFixed($update, $release, $title);
        if ($this->echoOutput && $show) {
            $category = $this->category->determineCategory($release->groups_id, $title, (string) ($release->fromname ?? ''));
            $this->echoReleaseInfo($release, $title, $category, $type, $method);
        }
    }

    /**
     * Apply fulltext evidence only while the release still has its confirmed identity and no link.
     * Snapshot predicates belong to the write itself, so a worker cannot win a read/write race.
     */
    public function updatePredbCandidate(object $release, string $title, int $predbId, string $source, bool $show = false): bool
    {
        $this->reset();
        $release = clone $release;
        $releaseId = (int) ($release->releases_id ?? 0);
        if ($releaseId <= 0 || $predbId <= 0 || ! isset($release->guid)
            || trim($title) === '' || ! mb_check_encoding($title, 'UTF-8')
            || mb_strlen($title, 'UTF-8') > 255 || preg_match('/\p{Cc}/u', $title) !== 0) {
            return false;
        }

        if (PredbSearchDocument::exactValue($title) === PredbSearchDocument::exactValue((string) $release->searchname)) {
            $attempt = $this->identityAttempt;
            if (! $this->withPredbIdentity($release, $predbId, $title, null, function () use ($release, $title, $predbId, $attempt): bool {
                $before = $this->predbSnapshotQuery($release)->lockForUpdate()->first();
                if ($before === null
                    || ! $this->allowsArchiveNamingLocked($before, $title)
                    || $this->predbSnapshotQuery($release)->update(['predb_id' => $predbId]) !== 1) {
                    return false;
                }
                $after = $this->releaseSnapshotQuery($release)->lockForUpdate()->first();
                $this->queueIdentityAttestation($before, $after, $attempt);

                return true;
            })) {
                return false;
            }
            $this->clearCompletedPar2Progress($releaseId, $this->completedReleaseSnapshot($release));
        } else {
            if (! $this->performDatabaseUpdate($release, $title, 'PreDB FT Exact, ', true, $predbId, $release)) {
                return false;
            }

            $this->incrementFixed(true, $release, $title);
            if ($this->echoOutput && $show) {
                $category = $this->category->determineCategory($release->groups_id, $title, (string) ($release->fromname ?? ''));
                $this->echoReleaseInfo($release, $title, $category, 'PreDB FT Exact, ', 'Title Match source: '.$source);
            }
        }

        $this->relid = $releaseId;
        $this->matched = $this->done = true;

        return true;
    }

    /** @return array{id: int, guid: string, name: ?string, searchname: ?string}|null */
    /** @return array{id: int, guid: string, name: ?string, searchname: ?string}|null */
    private function completedReleaseSnapshot(object $release, ?string $newTitle = null): ?array
    {
        if (self::$useRustFfi) {
            $supported = false;
            $snapshot = $this->completedReleaseSnapshotNative($release, $newTitle, $supported);
            if ($supported) {
                return $snapshot;
            }
        }

        return $this->completedReleaseSnapshotPhp($release, $newTitle);
    }

    /** @param-out bool $supported
     * @return array{id: int, guid: string, name: ?string, searchname: ?string}|null
     */
    private function completedReleaseSnapshotNative(object $release, ?string $newTitle = null, ?bool &$supported = null): ?array
    {
        $supported = false;
        $attributes = $release instanceof Release ? $release->getAttributes() : get_object_vars($release);
        $present = (int) isset($release->guid) | (array_key_exists('name', $attributes) ? 2 : 0)
            | (array_key_exists('searchname', $attributes) ? 4 : 0);
        // Read model accessors exactly as the original helper, only when the shape is valid.
        $fields = ['', '', '', '', ''];
        $flags = 0;
        if ($present === 7) {
            $id = (string) (int) ($release->releases_id ?? $release->id ?? 0);
            $guid = (string) $release->guid;
            $nameNull = $release->name === null;
            $name = $nameNull ? '' : (string) $release->name;
            $searchNull = $newTitle === null && $release->searchname === null;
            $search = $newTitle ?? ($searchNull ? '' : (string) $release->searchname);
            $fields = [$id, $guid, $name, $search, $newTitle ?? ''];
            $flags = ($nameNull ? 1 : 0) | ($searchNull ? 2 : 0) | ($newTitle !== null ? 4 : 0);
        }
        $raw = self::queryPassNative(8, self::queryPassFields($fields), $present, $flags);
        if ($raw === null) {
            return null;
        }
        $supported = true;
        if ($raw === '') {
            return null;
        }
        $fields = self::queryPassDecode($raw);
        if ($fields === null || count($fields) !== 6) {
            $supported = false;

            return null;
        }

        return ['id' => (int) $fields[0], 'guid' => $fields[1],
            'name' => $fields[2] === '1' ? null : $fields[3],
            'searchname' => $fields[4] === '1' ? null : $fields[5]];
    }

    /** @return array{id: int, guid: string, name: ?string, searchname: ?string}|null */
    private function completedReleaseSnapshotPhp(object $release, ?string $newTitle = null): ?array
    {
        $attributes = $release instanceof Release ? $release->getAttributes() : get_object_vars($release);
        if (! isset($release->guid) || ! array_key_exists('name', $attributes)
            || ! array_key_exists('searchname', $attributes)) {
            return null;
        }

        return ['id' => (int) ($release->releases_id ?? $release->id ?? 0),
            'guid' => (string) $release->guid,
            'name' => $release->name === null ? null : (string) $release->name,
            'searchname' => $newTitle ?? ($release->searchname === null ? null : (string) $release->searchname)];
    }

    /** @param null|callable(): bool $guard */
    public function updateMediaSnapshot(object $release, string $title, int $categoryId, ?callable $guard = null): bool
    {
        $this->clearIdentityAttestation();
        $this->matched = $this->done = false;
        $release = clone $release;
        $snapshot = $this->completedReleaseSnapshot($release);
        if ($snapshot === null || $snapshot['id'] <= 0 || $snapshot['guid'] === '' || $categoryId <= 0
            || trim($title) === '' || ! mb_check_encoding($title, 'UTF-8') || mb_strlen($title, 'UTF-8') > 255
            || preg_match('/\p{C}/u', $title) !== 0) {
            return false;
        }
        $release->releases_id = $snapshot['id'];
        $attempt = $this->identityAttempt;
        $aborted = new RuntimeException('Media release identity or admission changed.');
        try {
            $written = DB::transaction(function () use ($release, $title, $categoryId, $guard, $attempt, $aborted): bool {
                $before = $this->predbSnapshotQuery($release)->lockForUpdate()->first();
                if ($before === null || ($guard !== null && ! $guard())
                    || ! $this->allowsArchiveNamingLocked($before, $title)) {
                    return false;
                }
                $values = ['searchname' => $title, 'categories_id' => $categoryId, 'isrenamed' => 1, 'iscategorized' => 1, 'proc_pp' => 1];
                $updated = $this->predbSnapshotQuery($release)->update($values);
                $after = DB::table('releases')->where('id', $before->id)->lockForUpdate()->first();
                if ($after === null || $after->guid !== $before->guid || $after->name !== $before->name
                    || (int) $after->predb_id !== 0 || ($guard !== null && ! $guard())) {
                    throw $aborted;
                }
                foreach ($values as $column => $value) {
                    $actual = $after->{$column};
                    if (is_int($value)) {
                        $actual = (int) $actual;
                    }
                    if ($actual !== $value) {
                        throw $aborted;
                    }
                }
                if ($updated === 0 && $before->searchname !== $title) {
                    throw $aborted;
                }
                $this->queueIdentityAttestation($before, $after, $attempt);
                $this->clearCompletedPar2Progress((int) $before->id, $this->completedReleaseSnapshot($after));
                $coordinator = $this->searchSyncCoordinator;
                $releaseId = (int) $before->id;
                DB::afterCommit(static function () use ($coordinator, $releaseId): void {
                    $coordinator->request($releaseId);
                });

                return true;
            });
        } catch (RuntimeException $exception) {
            if ($exception !== $aborted) {
                throw $exception;
            }

            return false;
        }
        if ($written) {
            $this->matched = $this->done = true;
            $this->relid = $snapshot['id'];
        }

        return $written;
    }

    /** @return array{before: array{id: int, guid: string, name: ?string, searchname: ?string}, after: array{id: int, guid: string, name: ?string, searchname: ?string}}|null */
    public function lastCommittedIdentity(): ?array
    {
        return $this->committedIdentity;
    }

    private function clearIdentityAttestation(): void
    {
        $this->committedIdentity = null;
        $this->admittedIdentity = null;
        $this->identityAttempt++;
    }

    private function queueIdentityAttestation(object $before, ?object $after, int $attempt): void
    {
        $beforeIdentity = $this->completedReleaseSnapshot($before);
        $afterIdentity = $after === null ? null : $this->completedReleaseSnapshot($after);
        if ($beforeIdentity === null || $afterIdentity === null || $beforeIdentity['guid'] === ''
            || $beforeIdentity['id'] !== $afterIdentity['id'] || $beforeIdentity['guid'] !== $afterIdentity['guid']
            || $beforeIdentity['name'] !== $afterIdentity['name']) {
            return;
        }
        $this->admittedIdentity = $afterIdentity;
        DB::afterCommit(function () use ($beforeIdentity, $afterIdentity, $attempt): void {
            if ($this->identityAttempt === $attempt && $this->committedSnapshotMatches($afterIdentity)) {
                $this->committedIdentity = ['before' => $beforeIdentity, 'after' => $afterIdentity];
            }
        });
    }

    private function incrementFixed(bool $write, object $release, string $title): void
    {
        if (! $write) {
            $this->fixed++;

            return;
        }
        $snapshot = $this->admittedIdentity ?? $this->completedReleaseSnapshot($release, $title);
        DB::afterCommit(function () use ($snapshot): void {
            if ($snapshot === null || $this->committedSnapshotMatches($snapshot)) {
                $this->fixed++;
            }
        });
    }

    /** @param array{id: int, guid: string, name: ?string, searchname: ?string} $snapshot */
    private function committedSnapshotMatches(array $snapshot): bool
    {
        $current = DB::table('releases')->where('id', $snapshot['id'])->first(['id', 'guid', 'name', 'searchname']);

        return $current !== null && $this->completedReleaseSnapshot($current) === $snapshot;
    }

    /** @param array{id: int, guid: string, name: ?string, searchname: ?string}|null $snapshot */
    private function clearCompletedPar2Progress(int $releaseId, ?array $snapshot): void
    {
        if ($snapshot === null || $snapshot['id'] !== $releaseId) {
            return;
        }

        DB::afterCommit(static function () use ($snapshot): void {
            Par2RenameProgress::forgetCompletedSnapshot($snapshot);
        });
    }

    /** @return Builder<Release> */
    private function predbSnapshotQuery(object $release): Builder
    {
        return $this->releaseSnapshotQuery($release)->where('predb_id', 0);
    }

    /** @return Builder<Release> */
    private function releaseSnapshotQuery(object $release): Builder
    {
        $query = Release::query()->where('id', (int) $release->releases_id);
        if ($this->hasObservedAdditionalClaim($release)) {
            $claim = $release->observed_additional_pp_claim;
            if (! is_array($claim) || ! array_key_exists('token', $claim) || ! array_key_exists('claimed_at', $claim)
                || ($claim['token'] !== null && ! is_string($claim['token']))
                || ($claim['claimed_at'] !== null && ! is_string($claim['claimed_at']))) {
                return $query->whereRaw('1 = 0');
            }
            $token = $claim['token'];
            $stamp = $claim['claimed_at'];
            if ($token === null || $token === '') {
                if ($stamp !== null) {
                    return $query->whereRaw('1 = 0');
                }
                $query->whereNull(AdditionalCandidateQuery::CLAIMED_AT_COLUMN);
            } else {
                $parsed = $stamp === null ? false : \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $stamp);
                if ($parsed === false || $parsed->format('Y-m-d H:i:s') !== $stamp) {
                    return $query->whereRaw('1 = 0');
                }
                $query->where(AdditionalCandidateQuery::CLAIMED_AT_COLUMN, $stamp)
                    ->where(AdditionalCandidateQuery::CLAIMED_AT_COLUMN, '>=', AdditionalCandidateQuery::claimStaleBefore()->toDateTimeString());
            }
            if ($token === null) {
                $query->whereNull(AdditionalCandidateQuery::CLAIM_TOKEN_COLUMN);
            } else {
                $query->whereRaw('HEX('.AdditionalCandidateQuery::CLAIM_TOKEN_COLUMN.') = ?', [strtoupper(bin2hex($token))]);
            }
        }
        if (isset($release->observed_isrenamed)) {
            $query->where('isrenamed', (int) $release->observed_isrenamed);
        }
        if (isset($release->observed_par2_hash_generation) || property_exists($release, 'observed_par2_hash_generation')) {
            $generation = $release->observed_par2_hash_generation;
            if (! is_string($generation) || preg_match('/^(0|[1-9][0-9]*):(0|[1-9][0-9]*)$/D', $generation, $parts) !== 1
                || (string) (int) $parts[1] !== $parts[1] || (string) (int) $parts[2] !== $parts[2]) {
                return $query->whereRaw('1 = 0');
            }
            $query->whereRaw('(SELECT COALESCE(MAX(pfh.id), 0) FROM par_file_hashes pfh WHERE pfh.releases_id = releases.id) = ?', [(int) $parts[1]])
                ->whereRaw('(SELECT COUNT(*) FROM par_file_hashes pfh WHERE pfh.releases_id = releases.id) = ?', [(int) $parts[2]]);
        }
        $cast = DB::connection()->getDriverName() === 'sqlite' ? 'BLOB' : 'BINARY';
        $attributes = $release instanceof Release ? $release->getAttributes() : (array) $release;
        if (array_key_exists('observed_archive_source_digest', $attributes)) {
            $digest = $attributes['observed_archive_source_digest'];
            if (! is_string($digest) || preg_match('/^[a-f0-9]{64}$/D', $digest) !== 1) {
                return $query->whereRaw('1 = 0');
            }
            $query->whereExists(static function (\Illuminate\Database\Query\Builder $evidence) use ($digest, $cast): void {
                $evidence->selectRaw('1')->from('release_archive_naming_evidence')
                    ->whereColumn('release_archive_naming_evidence.releases_id', 'releases.id')
                    ->whereRaw("CAST(release_archive_naming_evidence.release_guid AS {$cast}) = CAST(releases.guid AS {$cast})")
                    ->where('release_archive_naming_evidence.source_sha256', $digest);
            });
        }
        foreach (['guid', 'name', 'searchname'] as $column) {
            if (($release->{$column} ?? null) === null) {
                $query->whereNull($column);
            } else {
                $query->whereRaw("CAST({$column} AS {$cast}) = CAST(? AS {$cast})", [(string) $release->{$column}]);
            }
        }

        return $query;
    }

    private function hasObservedAdditionalClaim(object $release): bool
    {
        return $release instanceof Release
            ? array_key_exists('observed_additional_pp_claim', $release->getAttributes())
            : isset($release->observed_additional_pp_claim) || property_exists($release, 'observed_additional_pp_claim');
    }

    /**
     * Run work that renames a release, and send its search index update only after the work returns, so a
     * caller's own transaction has committed first. A throwing callback sends no update.
     */
    public function deferSearchSync(int $releaseId, \Closure $work): void
    {
        $this->searchSyncCoordinator->beginReleaseScope($releaseId);

        try {
            $work();
        } catch (Throwable $exception) {
            $this->searchSyncCoordinator->discard($releaseId);
            $this->searchSyncCoordinator->finishReleaseScope();

            throw $exception;
        }

        $this->searchSyncCoordinator->finishReleaseScope();
    }

    /**
     * Check if the source is trusted enough to bypass plausibility checks.
     */
    protected function isTrustedSource(string $type, string $method, int $preId): bool
    {
        return (self::$useRustFfi ? $this->isTrustedSourceNative($type, $method, $preId) : null) ?? $this->isTrustedSourcePhp($type, $method, $preId);
    }

    private function isTrustedSourceNative(string $type, string $method, int $preId): ?bool
    {
        $raw = self::queryPassNative(5, self::queryPassFields([$type, $method]), $preId);

        return $raw === null ? null : $raw === '1';
    }

    protected function isTrustedSourcePhp(string $type, string $method, int $preId): bool
    {
        return
            (! empty($preId) && $preId > 0) ||
            str_starts_with($type, 'PreDB') ||
            str_starts_with($type, 'PreDb') ||
            $type === 'UID, ' ||
            $type === 'PAR2 hash, ' ||
            $type === 'CRC32, ' ||
            $type === 'SRR, ' ||
            stripos($method, 'Title Match') !== false ||
            stripos($method, 'file matched source') !== false ||
            stripos($method, 'PreDb') !== false ||
            stripos($method, 'preDB') !== false;
    }

    /**
     * Echo release information to CLI.
     *
     * @param  array<string, mixed>  $determinedCategory
     */
    public function echoReleaseInfo(
        object $release,
        string $newName,
        array $determinedCategory,
        string $type,
        string $method
    ): void {
        $groupName = UsenetGroup::getNameByID($release->groups_id);
        $oldCatName = Category::getNameByID($release->categories_id);
        $newCatName = Category::getNameByID($determinedCategory['categories_id']);

        if ($type === 'PAR2, ') {
            echo PHP_EOL;
        }

        echo PHP_EOL;

        cli()->primary('Release Information:');

        echo '  '.cli()->headerOver('New name:   ').cli()->primary(substr($newName, 0, 100)).PHP_EOL;
        echo '  '.cli()->headerOver('Old name:   ').cli()->primary(substr((string) $release->searchname, 0, 100)).PHP_EOL;
        echo '  '.cli()->headerOver('Use name:   ').cli()->primary(substr((string) $release->name, 0, 100)).PHP_EOL;
        echo PHP_EOL;

        echo '  '.cli()->headerOver('New cat:    ').cli()->primary($newCatName).PHP_EOL;
        echo '  '.cli()->headerOver('Old cat:    ').cli()->primary($oldCatName).PHP_EOL;
        echo '  '.cli()->headerOver('Group:      ').cli()->primary($groupName).PHP_EOL;
        echo PHP_EOL;

        echo '  '.cli()->headerOver('Method:     ').cli()->primary($type.$method).PHP_EOL;
        echo '  '.cli()->headerOver('Release ID: ').cli()->primary((string) $release->releases_id).PHP_EOL;

        if (! empty($release->filename)) {
            echo '  '.cli()->headerOver('Filename:   ').cli()->primary(substr((string) $release->filename, 0, 100)).PHP_EOL;
        }

        if ($type !== 'PAR2, ') {
            echo PHP_EOL;
        }
    }

    /**
     * Perform the actual database update.
     */
    protected function performDatabaseUpdate(
        object $release,
        string $newTitle,
        string $type,
        bool $nameStatus,
        int $preId,
        ?object $expectedSnapshot = null,
        ?string $filenameEvidence = null,
        ?callable $filenameGuard = null
    ): bool {
        $release = clone $release;
        $expectedSnapshot = $expectedSnapshot === null ? null : clone $expectedSnapshot;
        $releaseId = (int) $release->releases_id;
        $completedSnapshot = null;
        $attempt = $this->identityAttempt;
        $write = function (?string $canonicalTitle = null) use ($release, $releaseId, $newTitle, $type, $nameStatus, $preId, $expectedSnapshot, $filenameEvidence, $attempt, &$completedSnapshot): bool {
            $newTitle = $canonicalTitle ?? $newTitle;
            $actualRelease = DB::table('releases')->where('id', $releaseId)->lockForUpdate()->first();
            if ($actualRelease === null
                || ($preId > 0 && $this->renamesSeasonPackToEpisode((string) $actualRelease->searchname, $newTitle))) {
                return false;
            }
            if (! $this->allowsArchiveNamingLocked($actualRelease, $newTitle, $filenameEvidence)) {
                // Only an otherwise current canonical filename attempt may be
                // retried after finalization supplies its missing archive proof.
                $this->episodeNamingDeferredByArchiveProof = $preId > 0 && $filenameEvidence !== null
                    && SeasonPackDetector::isEpisodeName($newTitle) && $expectedSnapshot !== null
                    && $this->predbSnapshotQuery($expectedSnapshot)->exists();

                return false;
            }
            $completedSnapshot = $this->completedReleaseSnapshot($actualRelease, $newTitle);
            $query = $expectedSnapshot !== null
                ? $this->predbSnapshotQuery($expectedSnapshot)
                : Release::query()->where('id', $releaseId);
            if ($nameStatus === true) {
                $status = $this->getStatusColumnsForType($type);

                $updateColumns = [
                    ...TvProcessingCandidateQuery::resetColumns(),
                    'imdbid' => null,
                    'musicinfo_id' => '',
                    'consoleinfo_id' => '',
                    'bookinfo_id' => '',
                    'book_lookup_attempts' => 0,
                    'book_lookup_retry_at' => null,
                    'book_name_normalized_at' => null,
                    'anidbid' => '',
                    'predb_id' => $preId,
                    'searchname' => $newTitle,
                ];

                if (! empty($status)) {
                    foreach ($status as $key => $stat) {
                        $updateColumns = Arr::add($updateColumns, $key, $stat);
                    }
                }

                $affected = $query->update($updateColumns);
            } else {

                $affected = $query->update([
                    ...TvProcessingCandidateQuery::resetColumns(),
                    'imdbid' => null,
                    'musicinfo_id' => null,
                    'consoleinfo_id' => null,
                    'bookinfo_id' => null,
                    'book_lookup_attempts' => 0,
                    'book_lookup_retry_at' => null,
                    'book_name_normalized_at' => null,
                    'anidbid' => null,
                    'predb_id' => $preId,
                    'searchname' => $newTitle,
                    'iscategorized' => 1,
                ]);
            }

            if ($expectedSnapshot !== null && $affected !== 1) {
                return false;
            }

            $after = DB::table('releases')->where('id', $releaseId)->lockForUpdate()->first();
            if ($after !== null && $after->searchname === $newTitle) {
                $this->queueIdentityAttestation($actualRelease, $after, $attempt);
            }

            $nameFixed = new ReleaseNameFixed(
                $releaseId,
                (string) $release->searchname,
                $newTitle,
                (int) $release->categories_id,
                $release->groups_id,
                (string) ($release->fromname ?? ''),
                $completedSnapshot
            );
            DB::afterCommit(static function () use ($nameFixed): void {
                event($nameFixed);
            });

            return true;
        };
        $updated = $preId > 0
            ? $this->withPredbIdentity($expectedSnapshot ?? $release, $preId, $newTitle, $filenameEvidence, $write, $filenameGuard)
            : ($filenameGuard === null
                ? DB::transaction(static fn (): bool => $write())
                : $this->withFilenameGuard($expectedSnapshot ?? $release, $filenameGuard, $write));

        if ($updated) {
            if ($preId > 0 || ($nameStatus && ($this->getStatusColumnsForType($type)['isrenamed'] ?? 0) === 1)) {
                $this->clearCompletedPar2Progress($releaseId, $completedSnapshot);
            }
            $coordinator = $this->searchSyncCoordinator;
            DB::afterCommit(static function () use ($coordinator, $releaseId): void {
                $coordinator->request($releaseId);
            });
        }

        return $updated;
    }

    /**
     * Get the status columns to update for a given type.
     *
     * @return array<string, mixed>
     */
    /** @return array<string, mixed> */
    protected function getStatusColumnsForType(string $type): array
    {
        return (self::$useRustFfi ? $this->getStatusColumnsForTypeNative($type) : null) ?? $this->getStatusColumnsForTypePhp($type);
    }

    /** @return array<string, int>|null */
    private function getStatusColumnsForTypeNative(string $type): ?array
    {
        $raw = self::queryPassNative(6, $type);

        return $raw === null ? null : ($raw === '' ? [] : array_fill_keys(explode("\0", $raw), 1));
    }

    /** @return array<string, mixed> */
    protected function getStatusColumnsForTypePhp(string $type): array
    {
        return match ($type) {
            'NFO, ' => ['isrenamed' => 1, 'iscategorized' => 1, 'proc_nfo' => 1],
            'PAR2, ' => ['isrenamed' => 1, 'iscategorized' => 1, 'proc_par2' => 1],
            'Filenames, ', 'file matched source: ' => ['isrenamed' => 1, 'iscategorized' => 1, 'proc_files' => 1],
            'PreDB FT Exact, ' => ['isrenamed' => 1, 'iscategorized' => 1],
            'sorter, ' => ['isrenamed' => 1, 'iscategorized' => 1],
            'UID, ', 'Mediainfo, ' => ['isrenamed' => 1, 'iscategorized' => 1, 'proc_uid' => 1],
            'PAR2 hash, ' => ['isrenamed' => 1, 'iscategorized' => 1, 'proc_hash16k' => 1],
            'SRR, ' => ['isrenamed' => 1, 'iscategorized' => 1, 'proc_srr' => 1],
            'CRC32, ' => ['isrenamed' => 1, 'iscategorized' => 1, 'proc_crc32' => 1],
            default => [],
        };
    }

    /**
     * Update a single column in releases.
     */
    public function updateSingleColumn(string $column, int $status, int $id): void
    {
        if (! in_array($column, self::SINGLE_UPDATE_COLUMNS, true)) {
            throw new InvalidArgumentException("Unsupported release status column [{$column}].");
        }

        if ($id !== 0) {
            DB::update("UPDATE releases SET {$column} = ? WHERE id = ?", [$status, $id]);
            if (($column === 'proc_par2' && $status === 1) || ($column === 'predb_id' && $status > 0)) {
                $completed = DB::table('releases')->where('id', $id)->first(['id as releases_id', 'guid', 'name', 'searchname']);
                if ($completed !== null) {
                    $this->clearCompletedPar2Progress($id, $this->completedReleaseSnapshot($completed));
                }
            }
        }
    }

    /** Update a status only while the captured release remains unchanged and unlinked. */
    public function updateSnapshotColumn(object $release, string $column, int $status): bool
    {
        return $this->updateSnapshotStatus($release, $column, $status, 0);
    }

    /** Complete filename inspection only while the captured identity and PreDB link remain unchanged. */
    public function completeFilenameSnapshot(object $release, ?callable $filenameGuard = null): bool
    {
        $predbId = filter_var($release->predb_id ?? 0, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        if ($predbId === false) {
            return false;
        }

        return $filenameGuard === null
            ? $this->updateSnapshotStatus($release, 'proc_files', self::PROC_FILES_DONE, $predbId)
            : $this->withFilenameGuard($release, $filenameGuard, fn (): bool => $this->updateSnapshotStatus($release, 'proc_files', self::PROC_FILES_DONE, $predbId), 3);
    }

    private function updateSnapshotStatus(object $release, string $column, int $status, int $expectedPredbId): bool
    {
        if (! in_array($column, self::SINGLE_UPDATE_COLUMNS, true) || $column === 'predb_id') {
            throw new InvalidArgumentException("Unsupported release status column [{$column}].");
        }
        $release = clone $release;
        $snapshot = $this->completedReleaseSnapshot($release);
        if ($snapshot === null || $snapshot['id'] <= 0 || $snapshot['guid'] === '') {
            return false;
        }
        $release->releases_id = $snapshot['id'];
        $updated = $this->releaseSnapshotQuery($release)->where('predb_id', $expectedPredbId)->update([$column => $status]);
        if ($updated > 0 && $column === 'proc_par2' && $status === 1) {
            $this->clearCompletedPar2Progress($snapshot['id'], $snapshot);
        }

        return $updated > 0;
    }

    public function attachPredbId(int $releaseId, int $predbId): void
    {
        $this->reset();
        if ($releaseId <= 0 || $predbId <= 0) {
            return;
        }

        $release = DB::table('releases')->where('id', $releaseId)
            ->select(['id as releases_id', 'guid', 'name', 'searchname'])->first();
        if ($release !== null) {
            $this->attachPredbSnapshot($release, $predbId);
        }
    }

    /**
     * Attach same-name evidence only while its release identity is unchanged.
     * An established identical link is a match without another write.
     */
    public function attachPredbSnapshot(object $release, int $predbId, ?string $expectedTitle = null, ?string $filenameEvidence = null, ?callable $filenameGuard = null): bool
    {
        $this->reset();
        $releaseId = (int) ($release->releases_id ?? 0);
        $attributes = $release instanceof Release ? $release->getAttributes() : get_object_vars($release);
        if ($releaseId <= 0 || $predbId <= 0 || ! isset($release->guid, $release->searchname)
            || ! array_key_exists('name', $attributes)
            || ($expectedTitle !== null && PredbSearchDocument::exactValue($expectedTitle) !== PredbSearchDocument::exactValue((string) $release->searchname))) {
            return false;
        }

        $release = clone $release;
        $attached = false;
        $attempt = $this->identityAttempt;
        $accepted = $this->withPredbIdentity($release, $predbId, $expectedTitle ?? (string) $release->searchname, $filenameEvidence, function (string $canonicalTitle) use ($release, $predbId, $filenameEvidence, $attempt, &$attached): bool {
            $identity = $this->releaseSnapshotQuery($release);
            $before = (clone $identity)->lockForUpdate()->first();
            if ($before === null) {
                return false;
            }
            if (! $this->allowsArchiveNamingLocked($before, $canonicalTitle, $filenameEvidence)) {
                $this->episodeNamingDeferredByArchiveProof = $filenameEvidence !== null
                    && SeasonPackDetector::isEpisodeName($canonicalTitle);

                return false;
            }
            $alreadyLinked = (clone $identity)->where('predb_id', $predbId)->exists();
            $attached = ! $alreadyLinked && (clone $identity)->where('predb_id', 0)->update(['predb_id' => $predbId]) === 1;
            $accepted = $alreadyLinked || $attached || (clone $identity)->where('predb_id', $predbId)->exists();
            if ($accepted) {
                $after = (clone $identity)->where('predb_id', $predbId)->lockForUpdate()->first();
                $this->queueIdentityAttestation($before, $after, $attempt);
            }

            return $accepted;
        }, $filenameGuard);
        if (! $accepted) {
            return false;
        }
        if ($attached) {
            $this->clearCompletedPar2Progress($releaseId, $this->completedReleaseSnapshot($release));
        }

        $this->relid = $releaseId;
        $this->matched = $this->done = true;

        return true;
    }

    /**
     * Search documents and donor snapshots are discovery evidence, not the current canonical row.
     * Hold its lock through the release write and roll back if either observation loses identity.
     *
     * @param  callable(string): bool  $write
     */
    private function withPredbIdentity(object $release, int $predbId, string $title, ?string $filenameEvidence, callable $write, ?callable $filenameGuard = null): bool
    {
        $lostIdentity = new RuntimeException('PreDB identity changed during release matching.');
        try {
            return $this->predbIdentityTransaction(function () use ($release, $predbId, $title, $filenameEvidence, $write, $lostIdentity, $filenameGuard): bool {
                if ($this->releaseSnapshotQuery($release)->lockForUpdate()->first() === null) {
                    return false;
                }
                if ($filenameGuard !== null && ! $this->filenameGuardAccepted($filenameGuard)) {
                    return false;
                }
                $canonicalTitle = $this->predbCanonicalTitle($predbId, $title, $filenameEvidence);
                if ($canonicalTitle === null) {
                    return false;
                }
                $accepted = $write($canonicalTitle);
                if ($accepted && ($this->predbCanonicalTitle($predbId, $title, $filenameEvidence) !== $canonicalTitle
                    || ($filenameGuard !== null && ! $this->filenameGuardAccepted($filenameGuard)))) {
                    throw $lostIdentity;
                }

                return $accepted;
            });
        } catch (RuntimeException $exception) {
            if ($exception !== $lostIdentity) {
                throw $exception;
            }

            $this->clearIdentityAttestation();

            return false;
        }
    }

    /** @param callable(): bool $guard
     * @param  callable(): bool  $write
     */
    private function withFilenameGuard(object $release, callable $guard, callable $write, int $attempts = 1): bool
    {
        $lostAuthority = new RuntimeException('Filename authority changed during release matching.');
        try {
            return $this->predbIdentityTransaction(function () use ($release, $guard, $write, $lostAuthority): bool {
                if ($this->releaseSnapshotQuery($release)->lockForUpdate()->first() === null || ! $this->filenameGuardAccepted($guard)) {
                    return false;
                }
                $accepted = $write();
                if ($accepted && ! $this->filenameGuardAccepted($guard)) {
                    throw $lostAuthority;
                }

                return $accepted;
            }, $attempts);
        } catch (RuntimeException $exception) {
            if ($exception !== $lostAuthority) {
                throw $exception;
            }

            $this->clearIdentityAttestation();

            return false;
        }
    }

    /**
     * Rechecking database-backed authority can return a different value after a write.
     *
     * @param  callable(): bool  $guard
     *
     * @phpstan-impure
     */
    private function filenameGuardAccepted(callable $guard): bool
    {
        return $guard();
    }

    /**
     * Root-owned cleanup only; existing caller-owned transactions retain their owner.
     *
     * @param  callable(): bool  $write
     */
    private function predbIdentityTransaction(callable $write, int $attempts = 1): bool
    {
        return $this->runOwnedPredbTransaction(DB::connection(), $write, $attempts);
    }

    /** Read-only preview evidence; the guarded write must independently validate it again. */
    public function currentPredbTitle(int $predbId, string $title, ?string $filenameEvidence = null): ?string
    {
        return $this->predbCanonicalTitle($predbId, $title, $filenameEvidence, false);
    }

    private function predbCanonicalTitle(int $predbId, string $title, ?string $filenameEvidence, bool $lock = true): ?string
    {
        if ($predbId <= 0 || trim($title) === '' || ! mb_check_encoding($title, 'UTF-8')
            || mb_strlen($title, 'UTF-8') > 255 || preg_match('/\p{C}/u', $title) !== 0) {
            return null;
        }
        $query = DB::table('predb')->where('id', $predbId);
        if ($lock) {
            $query->lockForUpdate();
        }
        $row = $query->first();
        if ($row === null || ! mb_check_encoding((string) $row->title, 'UTF-8')
            || mb_strlen((string) $row->title, 'UTF-8') > 255 || preg_match('/\p{C}/u', (string) $row->title) !== 0
            || PredbSearchDocument::exactValue((string) $row->title) !== PredbSearchDocument::exactValue($title)) {
            return null;
        }
        if ($filenameEvidence === null) {
            return (string) $row->title;
        }

        return (new OriginalFilenameEvidence($filenameEvidence))->accepts((string) $row->title, (string) ($row->filename ?? ''))
            ? (string) $row->title : null;
    }

    /**
     * Check if a release matches a PreDB entry.
     *
     * @return array<string, mixed>
     */
    public function checkPreDbMatch(object $release, string $textstring): ?array
    {
        if (! preg_match_all(self::PREDB_REGEX, $textstring, $hits) || preg_match('/Source\s:/i', $textstring)) {
            return null;
        }

        $candidates = [];
        foreach ($hits as $hit) {
            foreach ($hit as $value) {
                $title = trim($value);
                if ($title !== '') {
                    $candidates[$title] = $title;
                }
            }
        }

        if ($candidates === []) {
            return null;
        }

        $candidateValues = array_values($candidates);
        $rows = DB::select(
            'SELECT id, title FROM predb WHERE title IN ('.implode(',', array_fill(0, count($candidateValues), '?')).')',
            $candidateValues
        );
        $matches = [];
        foreach ($rows as $row) {
            $matches[PredbSearchDocument::exactValue((string) $row->title)] = ['title' => (string) $row->title, 'id' => (int) $row->id];
        }

        foreach ($candidateValues as $candidate) {
            $identity = PredbSearchDocument::exactValue($candidate);
            if (isset($matches[$identity])) {
                return $matches[$identity];
            }
        }

        return null;
    }

    /**
     * A release named as a whole season is never renamed to a single episode of it.
     */
    private function renamesSeasonPackToEpisode(string $currentName, string $newName): bool
    {
        return (self::$useRustFfi ? $this->renamesSeasonPackToEpisodeNative($currentName, $newName) : null) ?? $this->renamesSeasonPackToEpisodePhp($currentName, $newName);
    }

    private function renamesSeasonPackToEpisodeNative(string $currentName, string $newName): ?bool
    {
        $raw = self::queryPassNative(7, self::queryPassFields([$currentName, $newName]));

        return $raw === null ? null : $raw === '1';
    }

    private function renamesSeasonPackToEpisodePhp(string $currentName, string $newName): bool
    {
        return SeasonPackDetector::isSeasonPackPhp($currentName)
            && SeasonPackDetector::isEpisodeNamePhp($newName)
            && ! SeasonPackDetector::isSeasonPackPhp($newName);
    }

    private function allowsArchiveNamingLocked(object $release, string $title, ?string $filenameEvidence = null): bool
    {
        return ! SeasonPackDetector::isEpisodeName($title)
            || app(ReleaseArchiveNamingEvidence::class)->allowsEpisodeLocked($release, $title, $filenameEvidence);
    }

    private function allowsArchiveNamingPreview(object $release, string $title): bool
    {
        if (! SeasonPackDetector::isEpisodeName($title)) {
            return true;
        }

        return DB::transaction(function () use ($release, $title): bool {
            $current = $this->releaseSnapshotQuery($release)->lockForUpdate()->first();

            return $current !== null && $this->allowsArchiveNamingLocked($current, $title);
        });
    }

    /**
     * Reset status variables for new processing.
     */
    public function reset(): void
    {
        $this->clearIdentityAttestation();
        $this->resetArchiveNamingDeferral();
        $this->done = $this->matched = false;
    }

    public function resetArchiveNamingDeferral(): void
    {
        $this->episodeNamingDeferredByArchiveProof = false;
    }

    public function wasEpisodeNamingDeferredByArchiveProof(): bool
    {
        return $this->episodeNamingDeferredByArchiveProof;
    }

    /**
     * Increment the checked counter.
     */
    public function incrementChecked(): void
    {
        $this->checked++;
    }

    /**
     * Get the current statistics.
     *
     * @return array<string, mixed>
     */
    public function getStats(): array
    {
        return [
            'fixed' => $this->fixed,
            'checked' => $this->checked,
            'matched' => $this->matched,
            'done' => $this->done,
        ];
    }

    /** @return list<string>|null */
    private static function queryPassDecode(string $raw): ?array
    {
        $fields = [];
        $offset = 0;
        $length = strlen($raw);
        while ($offset < $length) {
            if ($length - $offset < 4) {
                return null;
            }
            $size = unpack('Vsize', substr($raw, $offset, 4))['size'];
            $offset += 4;
            if ($size > $length - $offset) {
                return null;
            }
            $fields[] = substr($raw, $offset, $size);
            $offset += $size;
        }

        return $fields;
    }
}
