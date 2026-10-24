<?php

declare(strict_types=1);

namespace App\Services\NameFixing;

use App\Enums\Par2RenameOutcome;
use App\Exceptions\IncompletePredbMatchException;
use App\Facades\Search;
use App\Services\NameFixing\Data\FilenamePass;
use App\Services\NameFixing\Extractors\FileNameExtractor;
use App\Services\NameFixing\Extractors\NfoNameExtractor;
use App\Services\NNTP\NNTPService;
use App\Services\Nzb\NzbContentsService;
use App\Services\Predb\PredbIdentityLookup;
use App\Services\Predb\PredbIdentityPolicy;
use App\Support\Par2FileHashEvidence;
use App\Support\PredbSearchDocument;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Cache\Lock;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Main service for name fixing operations.
 *
 * Orchestrates the various name fixing sources (NFO, Files, CRC, SRR, etc.)
 * and handles the overall processing flow.
 */
class NameFixingService
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

    public const PREDB_IDENTITY_POLICY = '0fa60dd1f7515a7d75cd1bad1c8249e06d9c401ccc4f92ecdddd9663d0517bcc';

    private const PREDB_CANDIDATE_PAGE_SIZE = 21;

    private const PREDB_CANDIDATE_MAX_PAGES = 10;

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

    protected ReleaseUpdateService $updateService;

    protected NameCheckerService $checkerService;

    protected NfoNameExtractor $nfoExtractor;

    protected FileNameExtractor $fileExtractor;

    protected FileNameCleaner $fileNameCleaner;

    protected FilePrioritizer $filePrioritizer;

    protected PredbMatchSelector $predbMatchSelector;

    protected NameFixingQueryService $queries;

    protected DonorMatchSelector $donorMatchSelector;

    /**
     * @var array<string, array<string, mixed>|null>
     */
    protected array $predbMatchCache = [];

    /** @var array<string, bool> */
    protected array $predbEvidenceCache = [];

    protected bool $echoOutput;

    protected int $_totalReleases = 0;

    public function __construct(
        ?ReleaseUpdateService $updateService = null,
        ?NameCheckerService $checkerService = null,
        ?NfoNameExtractor $nfoExtractor = null,
        ?FileNameExtractor $fileExtractor = null,
        ?FileNameCleaner $fileNameCleaner = null,
        ?FilePrioritizer $filePrioritizer = null,
        ?PredbMatchSelector $predbMatchSelector = null,
        ?NameFixingQueryService $queries = null,
        ?DonorMatchSelector $donorMatchSelector = null
    ) {
        $this->updateService = $updateService ?? new ReleaseUpdateService;
        $this->checkerService = $checkerService ?? new NameCheckerService;
        $this->nfoExtractor = $nfoExtractor ?? new NfoNameExtractor;
        $this->fileExtractor = $fileExtractor ?? new FileNameExtractor;
        $this->fileNameCleaner = $fileNameCleaner ?? new FileNameCleaner;
        $this->filePrioritizer = $filePrioritizer ?? new FilePrioritizer;
        $this->predbMatchSelector = $predbMatchSelector ?? new PredbMatchSelector($this->fileNameCleaner);
        $this->queries = $queries ?? new NameFixingQueryService;
        $this->donorMatchSelector = $donorMatchSelector ?? new DonorMatchSelector;
        $this->echoOutput = config('nntmux.echocli');
    }

    /**
     * Fix names using NFO content.
     */
    public function fixNamesWithNfo(int $time, bool $echo, int $cats, bool $nameStatus, bool $show): void
    {
        $this->echoStartMessage($time, '.nfo files');
        $type = 'NFO, ';
        $preIdOnly = $cats === 3;

        if (! $this->startBatch(NameFixingQueryService::SOURCE_NFO, $time, $cats, ' releases to process.')) {
            cli()->info('Nothing to fix.');

            return;
        }

        foreach ($this->candidateBatches(NameFixingQueryService::SOURCE_NFO, $time, $cats) as $releases) {
            $releases = array_map(static fn (object $release): object => clone $release, $releases);
            $nfos = $this->queries->groupByReleaseId($this->queries->nfoRows($this->releaseIds($releases)));

            foreach ($releases as $release) {
                $this->updateService->incrementChecked();
                $this->updateService->reset();
                $nfo = $nfos[(int) $release->releases_id][0] ?? null;
                if ($nfo === null) {
                    if ($echo && $nameStatus) {
                        $this->updateService->updateSnapshotColumn($release, 'proc_nfo', self::PROC_NFO_DONE);
                    }

                    continue;
                }

                $release->textstring = (string) ($nfo->textstring ?? '');
                $this->processNfoCandidate($release, $echo, $nameStatus, $show, true, ! $preIdOnly);

                $this->echoRenamed($show);
            }
        }

        $this->echoFoundCount($echo, ' NFO\'s');
    }

    /**
     * Fix names using file names.
     */
    public function fixNamesWithFiles(int $time, bool $echo, int $cats, bool $nameStatus, bool $show): void
    {
        $this->echoStartMessage($time, 'file names');
        $type = 'Filenames, ';

        if (! $this->startBatch(NameFixingQueryService::SOURCE_FILES, $time, $cats, ' file names to process.')) {
            cli()->info('Nothing to fix.');

            return;
        }

        foreach ($this->candidateBatches(NameFixingQueryService::SOURCE_FILES, $time, $cats) as $releases) {
            $files = $this->queries->groupByReleaseId($this->queries->fileRows($this->releaseIds($releases)));

            foreach ($releases as $release) {
                $this->processFileCandidates($release, $files[(int) $release->releases_id] ?? [], $echo, $nameStatus, $show);
            }
        }

        $this->echoFoundCount($echo, ' files');
    }

    /**
     * Fix names using SRR files.
     */
    public function fixNamesWithSrr(int $time, bool $echo, int $cats, bool $nameStatus, bool $show): void
    {
        $this->echoStartMessage($time, 'SRR file names');
        $type = 'SRR, ';

        if (! $this->startBatch(NameFixingQueryService::SOURCE_SRR, $time, $cats, ' srr file extensions to process.')) {
            cli()->info('Nothing to fix.');

            return;
        }

        foreach ($this->candidateBatches(NameFixingQueryService::SOURCE_SRR, $time, $cats) as $releases) {
            $files = $this->queries->groupByReleaseId(
                $this->queries->fileRows($this->releaseIds($releases), NameFixingQueryService::SOURCE_SRR)
            );

            foreach ($releases as $release) {
                $this->updateService->reset();
                $this->updateService->incrementChecked();

                foreach ($files[(int) $release->releases_id] ?? [] as $file) {
                    $candidate = clone $release;
                    $candidate->textstring = (string) $file->textstring;
                    if ($this->srrNameCheck($candidate, $echo, $type, $nameStatus, $show)) {
                        break;
                    }
                }

                if (! $this->updateService->matched) {
                    $this->markProcessed($echo, $nameStatus, 'proc_srr', (int) $release->releases_id);
                }

                $this->echoRenamed($show);
            }
        }

        $this->echoFoundCount($echo, ' files');
    }

    /**
     * Fix names using CRC32 hashes.
     */
    public function fixNamesWithCrc(int $time, bool $echo, int $cats, bool $nameStatus, bool $show): void
    {
        $this->echoStartMessage($time, 'CRC32');
        $type = 'CRC32, ';

        if (! $this->startBatch(NameFixingQueryService::SOURCE_CRC, $time, $cats, ' CRC32\'s to process.')) {
            cli()->info('Nothing to fix.');

            return;
        }

        foreach ($this->candidateBatches(NameFixingQueryService::SOURCE_CRC, $time, $cats) as $releases) {
            $files = $this->queries->groupByReleaseId(
                $this->queries->fileRows($this->releaseIds($releases), NameFixingQueryService::SOURCE_CRC)
            );
            $crcs = $this->distinctValues($files, 'crc32');
            $donors = $this->queries->crcDonors($crcs);

            foreach ($releases as $release) {
                $this->updateService->reset();
                $this->updateService->incrementChecked();
                $prioritized = [];

                foreach ($files[(int) $release->releases_id] ?? [] as $file) {
                    $priority = $this->filePrioritizer->getCrcPriority((string) $file->filename);
                    $prioritized[$priority][(string) $file->crc32] = (string) $file->crc32;
                }

                ksort($prioritized);
                foreach ($prioritized as $values) {
                    foreach ($values as $crc) {
                        if ($this->applyDonorMatch($release, $donors[$crc] ?? [], 5, 'crcCheck: CRC32', $type, $echo, $nameStatus, $show)) {
                            break 2;
                        }
                    }
                }

                if (! $this->updateService->matched) {
                    $this->markProcessed($echo, $nameStatus, 'proc_crc32', (int) $release->releases_id);
                }

                $this->echoRenamed($show);
            }
        }

        $this->echoFoundCount($echo, ' crc32\'s');
    }

    /**
     * Fix names using Media info unique IDs.
     */
    public function fixNamesWithMedia(int $time, bool $echo, int $cats, bool $nameStatus, bool $show): void
    {
        $type = 'UID, ';
        $this->echoStartMessage($time, 'mediainfo Unique_IDs');

        if (! $this->startBatch(NameFixingQueryService::SOURCE_UID, $time, $cats, ' unique ids to process.')) {
            cli()->info('Nothing to fix.');

            return;
        }

        foreach ($this->candidateBatches(NameFixingQueryService::SOURCE_UID, $time, $cats) as $releases) {
            $media = $this->queries->groupByReleaseId($this->queries->mediaRows($this->releaseIds($releases)));
            $uids = $this->distinctValues($media, 'uid');
            $donors = $this->queries->uidDonors($uids);

            foreach ($releases as $release) {
                $this->updateService->reset();
                $this->updateService->incrementChecked();

                foreach ($media[(int) $release->releases_id] ?? [] as $row) {
                    $uid = (string) ($row->uid ?? '');
                    if ($uid !== '' && $this->applyDonorMatch($release, $donors[$uid] ?? [], 10, 'uidCheck: Unique_ID', $type, $echo, $nameStatus, $show)) {
                        break;
                    }
                }

                if (! $this->updateService->matched) {
                    $this->markProcessed($echo, $nameStatus, 'proc_uid', (int) $release->releases_id);
                }

                $this->echoRenamed($show);
            }
        }

        $this->echoFoundCount($echo, ' UID\'s');
    }

    /**
     * Fix names using PAR2 hash_16K.
     */
    public function fixNamesWithParHash(int $time, bool $echo, int $cats, bool $nameStatus, bool $show): void
    {
        $type = 'PAR2 hash, ';
        $this->echoStartMessage($time, 'PAR2 hash_16K');

        if (! $this->startBatch(NameFixingQueryService::SOURCE_HASH, $time, $cats, ' hash_16K to process.')) {
            cli()->info('Nothing to fix.');

            return;
        }

        foreach ($this->candidateBatches(NameFixingQueryService::SOURCE_HASH, $time, $cats) as $releases) {
            $hashes = $this->queries->groupByReleaseId($this->queries->legacyHashRows($this->releaseIds($releases)));
            $hashValues = $this->distinctValues($hashes, 'hash');
            $donors = $this->queries->hashDonors($hashValues);

            foreach ($releases as $release) {
                $this->updateService->reset();
                $this->updateService->incrementChecked();

                $this->processPar2HashCandidate($release, $hashes[(int) $release->releases_id] ?? [], $donors, $echo, $nameStatus, $show);

                $this->echoRenamed($show);
            }
        }

        $this->echoFoundCount($echo, ' hashes');
    }

    /**
     * Check with pattern matchers (TV, Movie, Game, App).
     */
    protected function checkWithPatternMatchers(object $release, bool $echo, string $type, bool $nameStatus, bool $show, bool $preId): void
    {
        if ($type === 'NFO, ') {
            $release = clone $release;
            $release->textstring = $this->nfoExtractor->identityText($release->textstring);
        }

        // Check for PreDB match first
        $preDbMatch = $this->updateService->checkPreDbMatch($release, $release->textstring);
        if ($preDbMatch !== null) {
            $this->updateService->updateRelease(
                $release,
                $preDbMatch['title'],
                'preDB: Match',
                $echo,
                $type,
                $nameStatus,
                $show,
                $preDbMatch['id']
            );

            return;
        }

        if ($preId) {
            return;
        }

        // Try pattern checkers
        $result = $this->checkerService->check($release, $release->textstring);
        if ($result !== null) {
            $this->updateService->updateRelease(
                $release,
                $result->newName,
                $result->getFormattedMethod(),
                $echo,
                $type,
                $nameStatus,
                $show
            );
        }
    }

    /**
     * Check SRR file for release name.
     */
    protected function srrNameCheck(object $release, bool $echo, string $type, bool $nameStatus, bool $show): bool
    {
        $extractedName = null;

        if (preg_match('/^(.+)\.srr$/i', $release->textstring, $hit)) {
            $extractedName = $hit[1];
        } elseif (preg_match('/^(.+)\.srs$/i', $release->textstring, $hit)) {
            $extractedName = $hit[1];
        }

        if ($extractedName !== null) {
            if (preg_match('/[\\\\\/]([^\\\\\/]+)$/', $extractedName, $pathMatch)) {
                $extractedName = $pathMatch[1];
            }

            if (preg_match(ReleaseUpdateService::PREDB_REGEX, $extractedName)) {
                $this->updateService->updateRelease(
                    $release,
                    $extractedName,
                    'fileCheck: SRR extension',
                    $echo,
                    $type,
                    $nameStatus,
                    $show
                );

                return $this->updateService->matched;
            }
        }

        return false;
    }

    /**
     * Check PreDB for filename matches.
     */
    protected function preDbFileCheck(object $release, bool $echo, string $type, bool $nameStatus, bool $show, ?bool &$hadEvidence = null, ?bool &$lookupComplete = null, ?FilenamePass $observedPass = null, ?callable $filenameGuard = null): bool
    {
        $pass = $observedPass ?? $this->collectPredbFilenamePass([(string) $release->textstring]);
        $filenameGuard ??= $this->filenamePassGuard($release, $pass);
        $hadEvidence = $pass->lookups[0]['evidence'] ?? true;
        $lookupComplete = $pass->complete();
        $selected = $pass->uniqueMatches()[0] ?? null;

        if ($selected === null) {
            return false;
        }
        $matched = $this->applyPredbFilenameMatch($release, $selected['match'], $echo, $type, $nameStatus, $show, $filenameGuard);
        if ($echo && ! $matched) {
            $lookupComplete = false;
        }

        return $matched;
    }

    /** @param list<string> $filenames */
    private function collectPredbFilenamePass(array $filenames, int $minimumLength = 0): FilenamePass
    {
        $lookups = [];
        $eligible = [];
        $localRejections = [];
        $localPolicy = null;
        foreach ($filenames as $index => $filename) {
            $evidence = new OriginalFilenameEvidence($filename);
            $query = $this->fileNameCleaner->cleanForFilenameDiscovery($evidence);
            $lookups[$index] = ['filename' => $filename, 'match' => null, 'evidence' => true, 'complete' => true];
            if ($evidence->isLocallyRejectedPipe()) {
                $localRejections[] = $index;
                $localPolicy ??= (new PredbIdentityPolicy)->fingerprint();

                continue;
            }
            if (empty($query) || strlen($query) < $minimumLength) {
                continue;
            }
            $eligible[] = ['index' => $index, 'evidence' => $evidence, 'query' => $query];
        }
        $results = (new PredbIdentityLookup)->lookupMany(DB::connection(), array_column($eligible, 'evidence'));
        foreach ($eligible as $position => $observation) {
            $result = $results[$position];
            $lookup = &$lookups[$observation['index']];
            $lookup['complete'] = $result['status'] !== 'unknown';
            $lookup['evidence'] = $result['status'] !== 'absent';
            if ($result['status'] === 'unique') {
                $lookup['match'] = $this->findBestPredbFilenameMatch($observation['query'], $observation['evidence'], $result['candidate']);
            }
            unset($lookup);
        }

        if ($localRejections !== [] && ($localPolicy === null || (new PredbIdentityPolicy)->fingerprint() !== $localPolicy)) {
            foreach ($localRejections as $index) {
                $lookups[$index]['complete'] = false;
            }
        }

        return new FilenamePass($filenames, $lookups, $localPolicy);
    }

    /** @return list<string> */
    private function persistedFilenameInventory(object $release): array
    {
        return DB::table('release_files')->where('releases_id', (int) ($release->releases_id ?? $release->id ?? 0))
            ->pluck('name')->map(static fn (mixed $name): string => (string) $name)->all();
    }

    /** @param list<string>|null $inventory
     * @return callable(): bool
     */
    private function filenamePassGuard(object $release, FilenamePass $pass, int $minimumLength = 0, ?array $inventory = null): callable
    {
        $release = clone $release;
        $inventory ??= $this->persistedFilenameInventory($release);
        $inventory = array_values(array_unique($inventory, SORT_STRING));
        sort($inventory, SORT_STRING);
        $valid = true;

        return function () use ($release, $pass, $minimumLength, $inventory, &$valid): bool {
            if (! $valid) {
                return false;
            }
            if ($pass->localRejectionPolicy !== null && (new PredbIdentityPolicy)->fingerprint() !== $pass->localRejectionPolicy) {
                return $valid = false;
            }
            $current = DB::table('release_files')->where('releases_id', (int) ($release->releases_id ?? $release->id ?? 0))
                ->sharedLock()->pluck('name')->map(static fn (mixed $name): string => (string) $name)->all();
            $current = array_values(array_unique($current, SORT_STRING));
            sort($current, SORT_STRING);
            if ($current !== $inventory || ! $pass->complete()) {
                return $valid = false;
            }
            $fresh = $this->collectPredbFilenamePass($pass->filenames, $minimumLength);

            return $valid = $fresh->complete() && $fresh->lookups === $pass->lookups
                && $fresh->localRejectionPolicy === $pass->localRejectionPolicy;
        };
    }

    /** @param array<string, mixed> $bestMatch */
    private function applyPredbFilenameMatch(object $release, array $bestMatch, bool $echo, string $type, bool $nameStatus, bool $show, ?callable $filenameGuard = null): bool
    {
        $originalFilename = (string) $release->textstring;
        if ((int) ($bestMatch['id'] ?? 0) <= 0) {
            return false;
        }
        if (! $echo && $this->updateService->currentPredbTitle((int) ($bestMatch['id'] ?? 0), (string) $bestMatch['title'], $originalFilename) === null) {
            return false;
        }
        if (PredbSearchDocument::exactValue((string) ($bestMatch['title'] ?? '')) === PredbSearchDocument::exactValue((string) $release->searchname)) {
            if ($echo) {
                $this->updateService->attachPredbSnapshot($release, (int) ($bestMatch['id'] ?? 0), (string) $bestMatch['title'], $originalFilename, $filenameGuard);
            }

            return $this->updateService->matched;
        }

        $this->updateService->updateRelease(
            $release,
            $bestMatch['title'] ?? '',
            'PreDb: Filename match',
            $echo,
            $type,
            $nameStatus,
            $show,
            $bestMatch['id'] ?? null,
            $originalFilename,
            $filenameGuard
        );

        return $this->updateService->matched;
    }

    /**
     * Apply existing scorer policy only after certified SQL nomination.
     *
     * @param  array{id: int, title: string, filename: string}  $candidate
     * @return array<string, mixed>|null
     */
    private function findBestPredbFilenameMatch(string $query, OriginalFilenameEvidence $evidence, array $candidate): ?array
    {
        if (! $evidence->accepts((string) $candidate['title'], (string) $candidate['filename'])) {
            return null;
        }
        $eligible = [$candidate];
        // Certified uniqueness does not bypass generic-name and scoring policy.
        $match = $this->predbMatchSelector->selectBestMatch($evidence->stem, $eligible, $evidence)
            ?? $this->predbMatchSelector->selectBestMatch($evidence->basename, $eligible, $evidence)
            ?? $this->predbMatchSelector->selectBestMatch($query, $eligible, $evidence);
        if ($match === null || (int) ($match['id'] ?? 0) !== (int) $candidate['id']
            || PredbSearchDocument::exactValue((string) ($match['title'] ?? '')) !== PredbSearchDocument::exactValue((string) $candidate['title'])) {
            return null;
        }

        return $match;
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function findBestPredbMatch(string $fileName): ?array
    {
        if (array_key_exists($fileName, $this->predbMatchCache)) {
            return $this->predbMatchCache[$fileName];
        }

        if (count($this->predbMatchCache) >= 5000) {
            $this->predbMatchCache = [];
            $this->predbEvidenceCache = [];
        }

        return $this->fileNameCleaner->withMatchingReuse(fn (): ?array => $this->resolvePredbMatch($fileName));
    }

    /** @return array<string, mixed>|null */
    private function resolvePredbMatch(string $fileName): ?array
    {
        $searchText = self::predbSearchText($fileName);
        $results = $searchText === '' ? [] : Search::searchPredb($searchText);

        $this->predbEvidenceCache[$fileName] = $results !== [];

        $match = $this->predbMatchSelector->selectBestMatch($fileName, $results);
        if ($match !== null) {
            $matchId = (int) ($match['id'] ?? 0);
            $matchIdentity = PredbSearchDocument::exactValue((string) ($match['title'] ?? ''));
            $fileIdentity = PredbSearchDocument::exactValue($fileName);
            if ($matchId <= 0) {
                return $this->predbMatchCache[$fileName] = null;
            }
            foreach ($results as $result) {
                $candidate = is_array($result) ? $result : (array) $result;
                $candidateIdentity = PredbSearchDocument::exactValue((string) ($candidate['title'] ?? ''));
                $sameTitle = $candidateIdentity === $matchIdentity;
                $sameFilename = false;
                if ($matchIdentity !== $fileIdentity) {
                    $candidateFilename = $this->fileNameCleaner->cleanForMatching((string) ($candidate['filename'] ?? ''), preserveLeadingNumber: true);
                    $sameFilename = $candidateFilename !== false
                        && PredbSearchDocument::exactValue($candidateFilename) === $fileIdentity;
                }
                if (($sameTitle || $sameFilename)
                    && ((int) ($candidate['id'] ?? 0) !== $matchId || ! $sameTitle)) {
                    return $this->predbMatchCache[$fileName] = null;
                }
            }
        }

        return $this->predbMatchCache[$fileName] = $match;
    }

    /**
     * Release file names are machine generated, so they are searched as plain words. The PreDB search
     * keeps query operators for people typing searches; a stray parenthesis, quote or `|` in a hashed
     * name reached Manticore unescaped and failed the lookup with a syntax error. Everything except
     * letters, digits and the `.`, `_` and `-` separators becomes a space, and a leading `-` (a negation
     * operator) is dropped.
     */
    public static function predbSearchText(string $fileName): string
    {
        $text = preg_replace('/[^\p{L}\p{N}\s._-]+/u', ' ', $fileName) ?? '';
        $text = preg_replace('/(?<=^|\s)-+/', '', $text) ?? '';

        return trim(preg_replace('/\s+/', ' ', $text) ?? '');
    }

    /**
     * @return \Generator<int, list<object>>
     */
    protected function candidateBatches(string $source, int $time, int $categories): \Generator
    {
        $afterId = 0;

        do {
            $batch = $this->queries->candidateBatch($source, $time, $categories, $afterId);
            if ($batch === []) {
                break;
            }

            yield $batch;
            $afterId = (int) $batch[array_key_last($batch)]->releases_id;
        } while (count($batch) === NameFixingQueryService::BATCH_SIZE);
    }

    protected function startBatch(string $source, int $time, int $categories, string $message): bool
    {
        $total = $this->queries->countCandidates($source, $time, $categories);
        $this->_totalReleases = $total;

        if ($total === 0) {
            return false;
        }

        cli()->info(number_format($total).$message);

        return true;
    }

    /**
     * @param  list<object>  $releases
     * @return list<int>
     */
    protected function releaseIds(array $releases): array
    {
        return array_values(array_map(
            static fn (object $release): int => (int) $release->releases_id,
            $releases
        ));
    }

    /**
     * @param  array<int, list<object>>  $groupedRows
     * @return list<string>
     */
    /** @param array<int, list<object>> $groupedRows
     * @return list<string>
     */
    protected function distinctValues(array $groupedRows, string $column): array
    {
        return (self::$useRustFfi ? $this->distinctValuesNative($groupedRows, $column) : null) ?? $this->distinctValuesPhp($groupedRows, $column);
    }

    /** @param array<int, list<object>> $groupedRows
     * @return list<string>|null
     */
    private function distinctValuesNative(array $groupedRows, string $column): ?array
    {
        $fields = [];
        foreach ($groupedRows as $rows) {
            foreach ($rows as $row) {
                $fields[] = (string) ($row->{$column} ?? '');
            }
        }
        $raw = self::queryPassNative(18, self::queryPassFields($fields));

        return $raw === null ? null : self::queryPassDecode($raw);
    }

    /** @param array<int, list<object>> $groupedRows
     * @return list<string>
     */
    protected function distinctValuesPhp(array $groupedRows, string $column): array
    {
        $values = [];

        foreach ($groupedRows as $rows) {
            foreach ($rows as $row) {
                $value = (string) ($row->{$column} ?? '');
                if ($value !== '') {
                    $values[$value] = $value;
                }
            }
        }

        return array_values($values);
    }

    /**
     * Serialize hash completion and evidence requeue on the exact target. Strong
     * misses remain pending with a GUID-bound cooldown for later donor linkage.
     *
     * @param  list<object>  $legacyRows
     * @param  array<string, list<object>>  $legacyDonors
     */
    protected function processPar2HashCandidate(object $release, array $legacyRows, array $legacyDonors, bool $echo, bool $nameStatus, bool $show): bool
    {
        $release = clone $release;
        $release->observed_isrenamed = (int) ($release->isrenamed ?? 0);
        $releaseId = (int) $release->releases_id;
        if (! isset($release->guid) || ! property_exists($release, 'name') || ! property_exists($release, 'searchname')) {
            return false;
        }

        return DB::transaction(function () use ($release, $releaseId, $legacyRows, $legacyDonors, $echo, $nameStatus, $show): bool {
            $current = DB::table('releases')->where('id', $releaseId)->lockForUpdate()->first();
            if (! $this->par2HashSnapshotMatches($current, $release)) {
                return false;
            }
            $retry = DB::table('par2_hash_retry')->where('releases_id', $releaseId)->lockForUpdate()->first();
            if ($retry !== null && $retry->release_guid === $release->guid && (string) $retry->retry_at > now()->toDateTimeString()) {
                return false;
            }
            $strong = $this->queries->fullHashDonorsForRelease($releaseId, (string) $release->guid);
            $release->observed_par2_hash_generation = $strong['generation'];
            if ($strong['known']) {
                if (! $this->par2HashSnapshotMatches(DB::table('releases')->where('id', $releaseId)->lockForUpdate()->first(), $release)) {
                    return false;
                }
                $donor = $strong['usable'] && ! $strong['overflow']
                    ? $this->donorMatchSelector->selectCanonical($strong['donors']) : null;
                if ($this->queries->hashEvidenceGeneration($releaseId) !== $strong['generation']) {
                    return false;
                }
                if ($donor !== null) {
                    $this->updateService->updateRelease($release, (string) $donor->canonical_title,
                        'hashCheck: PAR2 full MD5 and exact file size', $echo, 'PAR2 hash, ', $nameStatus, $show, (int) $donor->predb_id);
                    if ($this->updateService->matched) {
                        if ($echo) {
                            DB::table('par2_hash_retry')->where('releases_id', $releaseId)
                                ->whereRaw('HEX(release_guid) = ?', [strtoupper(bin2hex((string) $release->guid))])->delete();
                        }

                        return true;
                    }
                }
                $after = DB::table('releases')->where('id', $releaseId)->lockForUpdate()->first();
                if ($echo && $nameStatus && $this->par2HashSnapshotMatches($after, $release)
                    && $this->queries->hashEvidenceGeneration($releaseId) === $strong['generation']) {
                    $this->deferPar2HashCandidate($release, $strong['generation'], $retry !== null);
                }

                return false;
            }
            foreach ($legacyRows as $row) {
                $hash = (string) ($row->hash ?? '');
                if ($hash !== '' && $this->applyDonorMatch($release, $legacyDonors[$hash] ?? [], 5,
                    'hashCheck: PAR2 hash_16K', 'PAR2 hash, ', $echo, $nameStatus, $show)) {
                    return true;
                }
            }
            $after = DB::table('releases')->where('id', $releaseId)->lockForUpdate()->first();
            if ($this->par2HashSnapshotMatches($after, $release)
                && ! DB::table('par_file_hashes')->where('releases_id', $releaseId)->exists()) {
                if ($echo && $nameStatus) {
                    $this->par2HashSnapshotQuery($release)->whereNotExists(function (Builder $query): void {
                        $query->selectRaw('1')->from('par_file_hashes')->whereColumn('par_file_hashes.releases_id', 'releases.id');
                    })->update(['proc_hash16k' => 1]);
                }
            }

            return false;
        });
    }

    private function par2HashSnapshotMatches(?object $current, object $release): bool
    {
        return $current !== null && $current->guid === $release->guid && $current->name === $release->name
            && $current->searchname === $release->searchname && (int) $current->predb_id === 0
            && (int) $current->isrenamed === (int) ($release->isrenamed ?? 0);
    }

    private function par2HashSnapshotQuery(object $release): Builder
    {
        $query = DB::table('releases')->where('id', (int) $release->releases_id)->where('predb_id', 0)
            ->where('isrenamed', (int) ($release->isrenamed ?? 0))
            ->whereRaw('HEX(guid) = ?', [strtoupper(bin2hex((string) $release->guid))]);
        foreach (['name', 'searchname'] as $column) {
            if ($release->{$column} === null) {
                $query->whereNull($column);
            } else {
                $query->whereRaw('HEX('.$column.') = ?', [strtoupper(bin2hex((string) $release->{$column}))]);
            }
        }

        return $query;
    }

    private function deferPar2HashCandidate(object $release, string $generation, bool $exists): void
    {
        [$lastId, $count] = explode(':', $generation);
        $eligible = $this->par2HashSnapshotQuery($release)
            ->whereRaw('(SELECT COALESCE(MAX(pfh.id), 0) FROM par_file_hashes pfh WHERE pfh.releases_id = releases.id) = ?', [(int) $lastId])
            ->whereRaw('(SELECT COUNT(*) FROM par_file_hashes pfh WHERE pfh.releases_id = releases.id) = ?', [(int) $count]);
        $retryAt = now()->addSeconds(Par2FileHashEvidence::RETRY_DELAY_SECONDS)->toDateTimeString();
        if ($exists) {
            DB::table('par2_hash_retry')->where('releases_id', (int) $release->releases_id)
                ->whereExists((clone $eligible)->selectRaw('1'))->update(['release_guid' => $release->guid, 'retry_at' => $retryAt]);
        } else {
            DB::table('par2_hash_retry')->insertOrIgnoreUsing(['releases_id', 'release_guid', 'retry_at'],
                $eligible->selectRaw('? AS releases_id, ? AS release_guid, ? AS retry_at', [(int) $release->releases_id, $release->guid, $retryAt]));
        }
    }

    /**
     * @param  list<object>  $donors
     */
    protected function applyDonorMatch(
        object $release,
        array $donors,
        int $tolerancePercent,
        string $method,
        string $type,
        bool $echo,
        bool $nameStatus,
        bool $show
    ): bool {
        $donor = $this->donorMatchSelector->select($donors, (int) $release->relsize, $tolerancePercent);
        if ($donor === null) {
            return false;
        }

        if (PredbSearchDocument::exactValue((string) $donor->searchname) === PredbSearchDocument::exactValue((string) $release->searchname)) {
            if ($echo) {
                $this->updateService->attachPredbSnapshot($release, (int) $donor->predb_id);
            }

            return $this->updateService->matched;
        }

        $this->updateService->updateRelease(
            $release,
            (string) $donor->searchname,
            $method,
            $echo,
            $type,
            $nameStatus,
            $show,
            (int) $donor->predb_id
        );

        return $this->updateService->matched;
    }

    /**
     * Freeze NFO provenance before lookup, extraction, naming, or completion.
     *
     * @phpstan-impure
     */
    private function processNfoCandidate(
        object $release,
        bool $echo,
        bool $nameStatus,
        bool $show,
        bool $allowExtraction = true,
        bool $allowPatternFallback = true,
        bool $markProcessed = true
    ): bool {
        $entry = clone $release;
        $this->updateService->reset();
        $text = (string) ($entry->textstring ?? '');
        if (! preg_match('/^=newz\[NZB\]=\w+/', $text)) {
            $view = $this->nfoExtractor->predbCandidates($text);
            if (! $view['complete']) {
                return false;
            }
            $rows = $view['titles'] === [] ? [] : DB::table('predb')->whereIn('title', $view['titles'])
                ->select(['id', 'title'])->limit(101)->get()->all();
            if ($rows !== []) {
                $identities = [];
                $expected = array_fill_keys(array_map(PredbSearchDocument::exactValue(...), $view['titles']), true);
                foreach ($rows as $row) {
                    if ((int) $row->id <= 0 || ! mb_check_encoding((string) $row->title, 'UTF-8')
                        || mb_strlen((string) $row->title, 'UTF-8') > 255 || preg_match('/\p{C}/u', (string) $row->title) !== 0
                        || ! isset($expected[PredbSearchDocument::exactValue((string) $row->title)])) {
                        return false;
                    }
                    $identities[(int) $row->id.':'.PredbSearchDocument::exactValue((string) $row->title)] = $row;
                }
                if (count($rows) === 101 || count($identities) !== 1) {
                    return false;
                }
                $match = array_values($identities)[0];
                $this->updateService->updateRelease($entry, (string) $match->title, 'preDB: NFO literal match',
                    $echo, 'NFO, ', $nameStatus, $show, (int) $match->id);

                return $this->updateService->matched;
            }
            if ($allowExtraction) {
                $result = $this->nfoExtractor->extractFromNfo($text);
                if ($result !== null) {
                    $this->updateService->updateRelease($entry, $result->newName, 'nfoCheck: '.$result->method,
                        $echo, 'NFO, ', $nameStatus, $show);
                }
                if (! $this->updateService->matched && $allowPatternFallback) {
                    $candidate = clone $entry;
                    $candidate->textstring = $this->nfoExtractor->identityText($text);
                    $result = $this->checkerService->check($candidate, $candidate->textstring);
                    if ($result !== null) {
                        $this->updateService->updateRelease($entry, $result->newName, $result->getFormattedMethod(),
                            $echo, 'NFO, ', $nameStatus, $show);
                    }
                }
            }
        }
        if (! $this->updateService->matched && $markProcessed && $echo && $nameStatus) {
            $this->updateService->updateSnapshotColumn($entry, 'proc_nfo', self::PROC_NFO_DONE);
        }

        return $this->updateService->matched;
    }

    /**
     * @param  list<object>  $files
     */
    protected function processFileCandidates(
        object $release,
        array $files,
        bool $echo,
        bool $nameStatus,
        bool $show,
        bool $incrementChecked = true,
        bool $showProgress = true
    ): void {
        $this->updateService->reset();
        if ($incrementChecked) {
            $this->updateService->incrementChecked();
        }
        $filenames = array_values(array_unique(array_map(
            static fn (object $file): string => (string) $file->textstring,
            $files
        )));
        $prioritized = $this->filePrioritizer->prioritizeForMatching($filenames);

        // No updater call may occur until every prioritized observation is resolved.
        $pass = $this->collectPredbFilenamePass($prioritized);
        $filenameGuard = $this->filenamePassGuard($release, $pass, 0, $filenames);
        foreach ($pass->uniqueMatches() as $selected) {
            $candidate = clone $release;
            $candidate->textstring = $selected['filename'];
            $this->applyPredbFilenameMatch($candidate, $selected['match'], $echo, 'Filenames, ', $nameStatus, $show, $filenameGuard);
            if ($this->updateService->matched) {
                break;
            }
        }
        $allLookupsComplete = $pass->complete() && ! $pass->hasMatches();
        $fallbacks = [];
        foreach ($pass->lookups as $lookup) {
            if (! $lookup['evidence']) {
                $candidate = clone $release;
                $candidate->textstring = $lookup['filename'];
                $fallbacks[] = $candidate;
            }
        }

        if (! $this->updateService->matched && $allLookupsComplete) {
            foreach ($fallbacks as $candidate) {
                $fileResult = $this->fileExtractor->extractFromFile($candidate->textstring);
                if ($fileResult !== null) {
                    $this->updateService->updateRelease(
                        $candidate,
                        $fileResult->newName,
                        'fileCheck: '.$fileResult->method,
                        $echo,
                        'Filenames, ',
                        $nameStatus,
                        $show,
                        0,
                        null,
                        $filenameGuard
                    );
                }
                if ($this->updateService->matched) {
                    break;
                }
            }
        }

        if (! $this->updateService->matched && $allLookupsComplete) {
            $this->completeFilenamePass($release, $filenames, $echo, $nameStatus, $filenameGuard, $pass);
        }

        if ($showProgress) {
            $this->echoRenamed($show);
        }
    }

    /** @param list<string> $filenames */
    private function completeFilenamePass(object $release, array $filenames, bool $echo, bool $nameStatus, ?callable $filenameGuard = null, ?FilenamePass $observedPass = null): void
    {
        if (! $echo || ! $nameStatus) {
            return;
        }
        $release = clone $release;
        $release->observed_isrenamed = (int) ($release->isrenamed ?? 0);
        $observedCategory = isset($release->categories_id) ? (int) $release->categories_id : null;
        $filenames = array_values(array_unique($filenames, SORT_STRING));
        sort($filenames, SORT_STRING);
        if ($observedPass === null) {
            return;
        }
        $observedNames = array_values(array_unique($observedPass->filenames, SORT_STRING));
        sort($observedNames, SORT_STRING);
        if ($observedNames !== $filenames) {
            return;
        }
        $filenameGuard ??= $this->filenamePassGuard($release, $observedPass, 0, $filenames);
        $completionGuard = function () use ($release, $filenames, $observedCategory, $filenameGuard): bool {
            $query = DB::table('releases')->where('id', (int) $release->releases_id)
                ->where('predb_id', $release->predb_id ?? 0)->where('isrenamed', $release->observed_isrenamed);
            if ($observedCategory !== null) {
                $query->where('categories_id', $observedCategory);
            }
            $current = $query->lockForUpdate()->first();
            if ($current === null) {
                return false;
            }
            // A locking read sees newly committed names even under REPEATABLE READ.
            $currentNames = DB::table('release_files')->where('releases_id', (int) $release->releases_id)
                ->sharedLock()->pluck('name')->map(static fn (mixed $name): string => (string) $name)->all();
            $currentNames = array_values(array_unique($currentNames));
            sort($currentNames, SORT_STRING);
            if ($currentNames !== $filenames) {
                return false;
            }

            return $filenameGuard();
        };
        $this->updateService->completeFilenameSnapshot($release, $completionGuard);
    }

    protected function markProcessed(bool $echo, bool $nameStatus, string $column, int $releaseId): void
    {
        if ($echo && $nameStatus) {
            $this->updateService->updateSingleColumn($column, 1, $releaseId);
        }
    }

    /**
     * Echo start message.
     */
    protected function echoStartMessage(int $time, string $type): void
    {
        cli()->info(
            sprintf(
                'Fixing search names %s using %s.',
                ($time === 1 ? 'in the past 6 hours' : 'since the beginning'),
                $type
            )
        );
    }

    /**
     * Echo found count.
     */
    protected function echoFoundCount(bool $echo, string $type): void
    {
        $stats = $this->updateService->getStats();
        if ($echo === true) {
            cli()->info(
                PHP_EOL.
                number_format($stats['fixed']).
                ' releases have had their names changed out of: '.
                number_format($stats['checked']).
                $type.'.'
            );
        } else {
            cli()->info(
                PHP_EOL.
                number_format($stats['fixed']).
                ' releases could have their names changed. '.
                number_format($stats['checked']).
                $type.' were checked.'
            );
        }
    }

    /**
     * Echo renamed progress.
     */
    protected function echoRenamed(bool $show): void
    {
        $stats = $this->updateService->getStats();

        // Show milestone message every 500 releases
        if ($stats['checked'] % 500 === 0 && $stats['checked'] > 0) {
            cli()->alternate(PHP_EOL.number_format($stats['checked']).' files processed.'.PHP_EOL);
        }

        // Show progress at meaningful intervals to reduce tmux pane clutter
        if ($show === true) {
            $percent = $this->_totalReleases > 0
                ? round(($stats['checked'] / $this->_totalReleases) * 100, 1)
                : 0;

            // Calculate progress interval - show update every 10% or at completion
            $progressInterval = max(1, (int) ($this->_totalReleases / 10));
            $isLastItem = $stats['checked'] === $this->_totalReleases;
            $isIntervalPoint = $stats['checked'] % $progressInterval === 0;

            // Only output at intervals or completion to keep tmux pane clean
            if ($isIntervalPoint || $isLastItem) {
                cli()->info(
                    'Renamed: '.number_format($stats['fixed']).
                    ' | Processed: '.number_format($stats['checked']).
                    '/'.number_format($this->_totalReleases).
                    ' ('.$percent.'%)'
                );
            }
        }
    }

    /**
     * Process one GUID-partitioned release batch using all standard name sources.
     *
     * @param  null|callable(object): (bool|Par2RenameOutcome)  $par2Processor
     * @return array{checked: int, fixed: int}
     */
    public function processStandardBatch(
        string $leftGuid,
        int $limit,
        bool $show,
        ?callable $par2Processor = null
    ): array {
        $releases = $this->reserveStandardBatch($leftGuid, $limit);
        if ($releases === []) {
            return ['checked' => 0, 'fixed' => 0];
        }

        $releases = array_map(static fn (object $release): object => clone $release, $releases);
        $releaseIds = $this->releaseIds($releases);
        $nfos = $this->queries->groupByReleaseId($this->queries->nfoRows($releaseIds));
        $files = $this->queries->groupByReleaseId($this->queries->fileRows($releaseIds));
        $media = $this->queries->groupByReleaseId($this->queries->mediaRows($releaseIds));
        $hashes = $this->queries->groupByReleaseId($this->queries->legacyHashRows($releaseIds));
        $uidDonors = $this->queries->uidDonors($this->distinctValues($media, 'uid'));
        $crcDonors = $this->queries->crcDonors($this->distinctValues($files, 'crc32'));
        $hashDonors = $this->queries->hashDonors($this->distinctValues($hashes, 'hash'));
        $fixedBefore = $this->updateService->fixed;

        foreach ($releases as $release) {
            $this->updateService->incrementChecked();
            $this->updateService->reset();
            $releaseId = (int) $release->releases_id;
            $releaseMedia = $media[$releaseId] ?? [];
            $releaseFiles = $files[$releaseId] ?? [];

            if ((int) $release->proc_uid === self::PROC_UID_NONE) {
                $this->updateService->reset();
                foreach ($releaseMedia as $row) {
                    $uid = (string) ($row->uid ?? '');
                    if ($uid !== '' && $this->applyDonorMatch($release, $uidDonors[$uid] ?? [], 10, 'uidCheck: Unique_ID', 'UID, ', true, true, $show)) {
                        break;
                    }
                }

                if (! $this->updateService->matched) {
                    foreach ($releaseMedia as $row) {
                        if (empty($row->movie_name)) {
                            continue;
                        }

                        $candidate = clone $release;
                        $candidate->movie_name = $row->movie_name;
                        $candidate->file_name = $row->file_name;
                        if ($this->mediaMovieNameCheck($candidate, true, 'Mediainfo, ', true, $show)) {
                            break;
                        }
                    }
                }

                if (! $this->updateService->matched) {
                    $this->updateService->updateSingleColumn('proc_uid', self::PROC_UID_DONE, $releaseId);
                }
            }

            if ($this->updateService->matched) {
                continue;
            }

            if ((int) $release->proc_crc32 === self::PROC_CRC_NONE) {
                $this->updateService->reset();
                $prioritizedCrcs = [];
                foreach ($releaseFiles as $file) {
                    $crc = (string) ($file->crc32 ?? '');
                    if ($crc === '') {
                        continue;
                    }

                    $priority = $this->filePrioritizer->getCrcPriority((string) $file->filename);
                    $prioritizedCrcs[$priority][$crc] = $crc;
                }
                ksort($prioritizedCrcs);

                foreach ($prioritizedCrcs as $crcs) {
                    foreach ($crcs as $crc) {
                        if ($this->applyDonorMatch($release, $crcDonors[$crc] ?? [], 5, 'crcCheck: CRC32', 'CRC32, ', true, true, $show)) {
                            break 2;
                        }
                    }
                }

                if (! $this->updateService->matched) {
                    $this->updateService->updateSingleColumn('proc_crc32', self::PROC_CRC_DONE, $releaseId);
                }
            }

            if ($this->updateService->matched) {
                continue;
            }

            if ((int) $release->proc_srr === self::PROC_SRR_NONE) {
                $this->updateService->reset();
                foreach ($releaseFiles as $file) {
                    $candidate = clone $release;
                    $candidate->textstring = (string) $file->textstring;
                    if ($this->srrNameCheck($candidate, true, 'SRR, ', true, $show)) {
                        break;
                    }
                }

                if (! $this->updateService->matched) {
                    $this->updateService->updateSingleColumn('proc_srr', self::PROC_SRR_DONE, $releaseId);
                }
            }

            if ($this->updateService->matched) {
                continue;
            }

            if ((int) $release->proc_hash16k === self::PROC_HASH16K_NONE) {
                $this->updateService->reset();
                $this->processPar2HashCandidate($release, $hashes[$releaseId] ?? [], $hashDonors, true, true, $show);
            }

            if ($this->updateService->matched) {
                continue;
            }

            if ((int) $release->proc_nfo === self::PROC_NFO_NONE) {
                $nfo = $nfos[$releaseId][0] ?? null;
                $release->textstring = (string) ($nfo->textstring ?? '');
                $this->processNfoCandidate($release, true, true, $show);
            }

            if ($this->updateService->matched) {
                continue;
            }

            if ((int) $release->proc_files === self::PROC_FILES_NONE) {
                $this->processFileCandidates($release, $releaseFiles, true, true, $show, false, false);
            }

            if ($this->updateService->matched) {
                continue;
            }

            if ((int) $release->proc_par2 === self::PROC_PAR2_NONE) {
                $this->updateService->reset();
                $result = $par2Processor !== null ? $par2Processor($release) : Par2RenameOutcome::Deferred;
                if ($result === true || $result === Par2RenameOutcome::Renamed) {
                    $this->updateService->fixed++;
                } elseif ($result === false || $result === Par2RenameOutcome::NoMatch) {
                    $this->updateService->updateSingleColumn('proc_par2', self::PROC_PAR2_DONE, $releaseId);
                }
            }
        }

        return [
            'checked' => count($releases),
            'fixed' => $this->updateService->fixed - $fixedBefore,
        ];
    }

    /** @return list<object> */
    private function reserveStandardBatch(string $leftGuid, int $limit): array
    {
        $cache = Cache::store((string) config('tmux.fix_names.cache_store', 'redis'));
        $store = $cache->getStore();
        if (! $store instanceof LockProvider) {
            throw new RuntimeException('The grouped rename cache store must support atomic locks.');
        }
        $key = 'rename:standard:cursor:'.$leftGuid;
        $lock = $store->lock($key.':lock', 120);
        if (! $lock instanceof Lock) {
            throw new RuntimeException('The grouped rename cache lock must support renewal.');
        }
        if (! $lock->get()) {
            return [];
        }
        try {
            $cursor = max(0, (int) $cache->get($key, 0));
            $releases = $this->queries->standardCandidateBatch($leftGuid, $limit, $cursor > 0 ? $cursor : null);
            if ($releases === [] && $cursor > 0) {
                $releases = $this->queries->standardCandidateBatch($leftGuid, $limit);
            }
            if (! $lock->refresh()) {
                return [];
            }
            if ($releases !== []) {
                $cache->forever($key, min($this->releaseIds($releases)));
            } else {
                $cache->forget($key);
            }

            return $releases;
        } finally {
            $lock->release();
        }
    }

    /**
     * Match one PreDB title against indexed release candidates.
     */
    public function matchPredbFulltext(object $pre, bool $show = false): int
    {
        return $this->matchPredbFulltextWithCompletion($pre, $show)['updated'];
    }

    /**
     * Keep successful writes separate from current canonical matches and unfinished candidates.
     *
     * @return array{updated: int, matched: bool, retry: bool}
     */
    public function matchPredbFulltextWithCompletion(object $pre, bool $show = false): array
    {
        $title = (string) ($pre->title ?? '');
        if (! mb_check_encoding($title, 'UTF-8') || mb_strlen($title, 'UTF-8') > 255
            || preg_match('/\p{Cc}/u', $title) !== 0
            || strlen($title) < 15 || ! preg_match('/([\w()]+[\s._-]){2,}[\w()]+-\w+/u', $title)) {
            return ['updated' => 0, 'matched' => false, 'retry' => false];
        }

        if (! Search::isAvailable()) {
            throw new RuntimeException('The configured search backend is unavailable for PreDB matching.');
        }

        $matched = false;
        if (Search::supportsReleaseCandidatePaging()) {
            $matches = $this->pagedLiteralPredbCandidates($title, (int) $pre->predb_id, $matched);
        } else {
            $searchResults = Search::searchReleases(['name' => $title, 'searchname' => $title], self::PREDB_CANDIDATE_PAGE_SIZE);
            $candidateIds = [];
            foreach ($searchResults as $key => $value) {
                $candidateId = is_numeric($value) ? (int) $value : (is_numeric($key) ? (int) $key : 0);
                if ($candidateId > 0) {
                    $candidateIds[$candidateId] = $candidateId;
                }
            }

            $matches = $this->literalPredbCandidates(array_values($candidateIds), $title);
            $matched = $matches === [] && $this->hasLinkedPredbCandidate(array_values($candidateIds), (int) $pre->predb_id, $title);
            if (count($searchResults) >= self::PREDB_CANDIDATE_PAGE_SIZE && count($matches) < 16) {
                throw new IncompletePredbMatchException('PreDB fulltext matching is incomplete: the search driver does not support candidate paging.');
            }
        }
        if (count($matches) >= 16) {
            $linked = $matched ? ['matched' => true, 'retry' => false] : $this->predbLinkCompletion((int) $pre->predb_id, $title);

            return ['updated' => -1, 'matched' => $linked['matched'], 'retry' => $linked['retry']];
        }

        $matching = 0;
        $retry = false;
        foreach ($matches as $release) {
            if ($this->updateService->updatePredbCandidate(
                $release,
                $title,
                (int) $pre->predb_id,
                (string) ($pre->source ?? ''),
                $show
            )) {
                $matching++;
                $matched = true;

                continue;
            }

            $current = DB::table('releases')->useWritePdo()
                ->where('id', (int) $release->releases_id)
                ->first(['predb_id', 'name', 'searchname']);
            if ($current === null) {
                continue;
            }
            if ((int) $current->predb_id === (int) $pre->predb_id
                && PredbSearchDocument::exactValue((string) $current->searchname) === PredbSearchDocument::exactValue($title)) {
                $matched = true;
            } elseif ((int) $current->predb_id === 0
                && ($this->hasLiteralPredbTitle((string) $current->name, $title)
                    || $this->hasLiteralPredbTitle((string) $current->searchname, $title))) {
                $retry = true;
            }
        }

        if (! $matched) {
            $linked = $this->predbLinkCompletion((int) $pre->predb_id, $title);
            $matched = $linked['matched'];
            $retry = $retry || $linked['retry'];
        }

        return ['updated' => $matching, 'matched' => $matched, 'retry' => $retry];
    }

    /** @return array{matched: bool, retry: bool} */
    private function predbLinkCompletion(int $predbId, string $title): array
    {
        $links = $this->queries->existingPredbLinks($predbId);
        foreach ($links as $release) {
            if (PredbSearchDocument::exactValue((string) $release->searchname) === PredbSearchDocument::exactValue($title)) {
                return ['matched' => true, 'retry' => false];
            }
        }

        return ['matched' => false, 'retry' => count($links) >= 17];
    }

    /**
     * @param  list<int>  $ids
     * @return list<object>
     */
    private function literalPredbCandidates(array $ids, string $title): array
    {
        return array_values(array_filter(
            $this->queries->confirmPredbCandidates($ids, $title),
            fn (object $release): bool => $this->hasLiteralPredbTitle((string) ($release->name ?? ''), $title)
                || $this->hasLiteralPredbTitle((string) ($release->searchname ?? ''), $title)
        ));
    }

    /**
     * @param  list<int>  $ids
     */
    private function hasLinkedPredbCandidate(array $ids, int $predbId, string $title): bool
    {
        foreach ($this->queries->linkedPredbCandidates($ids, $predbId) as $release) {
            if (PredbSearchDocument::exactValue((string) $release->searchname) === PredbSearchDocument::exactValue($title)) {
                return true;
            }
        }

        return false;
    }

    /** @return list<object> */
    private function pagedLiteralPredbCandidates(string $title, int $predbId, bool &$matched): array
    {
        $matches = [];
        $afterId = 0;
        for ($pageNumber = 0; $pageNumber < self::PREDB_CANDIDATE_MAX_PAGES; $pageNumber++) {
            $response = Search::searchReleaseCandidatesPage($title, self::PREDB_CANDIDATE_PAGE_SIZE, $afterId);
            if (($response['available'] ?? false) !== true) {
                throw new RuntimeException('The configured search backend is unavailable for paged PreDB matching.');
            }
            $page = $this->validatePredbCandidatePage($response, $afterId);
            foreach ($this->literalPredbCandidates($page['ids'], $title) as $release) {
                $matches[(int) $release->releases_id] = $release;
            }
            $pageMatched = ! $matched && $this->hasLinkedPredbCandidate($page['ids'], $predbId, $title);
            $matched = $matched || $pageMatched;
            if (count($matches) >= 16 || ! $page['has_more']) {
                return array_values($matches);
            }
            $afterId = $page['last_id'];
        }

        throw new IncompletePredbMatchException('PreDB fulltext matching is incomplete: the 210-candidate scan budget was reached.');
    }

    /**
     * @param  array<string, mixed>  $page
     * @return array{ids: list<int>, has_more: bool, last_id: int}
     */
    /** @param array<string, mixed> $page
     * @return array{ids: list<int>, has_more: bool, last_id: int}
     */
    private function validatePredbCandidatePage(array $page, int $afterId): array
    {
        return (self::$useRustFfi ? $this->validatePredbCandidatePageNative($page, $afterId) : null) ?? $this->validatePredbCandidatePagePhp($page, $afterId);
    }

    /** @param array<string, mixed> $page
     * @return array{ids: list<int>, has_more: bool, last_id: int}|null
     */
    private function validatePredbCandidatePageNative(array $page, int $afterId): ?array
    {
        $ids = $page['ids'] ?? null;
        $hasMore = $page['has_more'] ?? null;
        if (! is_array($ids) || ! array_is_list($ids) || ! is_bool($hasMore)) {
            return null;
        }
        $fields = [];
        foreach ($ids as $id) {
            if (! is_int($id)) {
                return null;
            }
            $fields[] = (string) $id;
        }
        $raw = self::queryPassNative(16, self::queryPassFields($fields), $afterId, (int) $hasMore);
        if ($raw === null) {
            return null;
        }
        if ($raw !== '') {
            throw new IncompletePredbMatchException($raw === 'boundaries'
                ? 'PreDB fulltext matching is incomplete: invalid candidate page boundaries.'
                : 'PreDB fulltext matching is incomplete: candidate IDs did not advance in order.');
        }

        return ['ids' => $ids, 'has_more' => $hasMore, 'last_id' => $ids === [] ? $afterId : $ids[array_key_last($ids)]];
    }

    /** @param array<string, mixed> $page
     * @return array{ids: list<int>, has_more: bool, last_id: int}
     */
    private function validatePredbCandidatePagePhp(array $page, int $afterId): array
    {
        $ids = $page['ids'] ?? null;
        $hasMore = $page['has_more'] ?? null;
        if (! is_array($ids) || ! array_is_list($ids) || ! is_bool($hasMore)
            || count($ids) > self::PREDB_CANDIDATE_PAGE_SIZE || ($hasMore && count($ids) < self::PREDB_CANDIDATE_PAGE_SIZE)) {
            throw new IncompletePredbMatchException('PreDB fulltext matching is incomplete: invalid candidate page boundaries.');
        }
        $validated = [];
        $lastId = $afterId;
        foreach ($ids as $id) {
            if (! is_int($id) || $id <= $lastId) {
                throw new IncompletePredbMatchException('PreDB fulltext matching is incomplete: candidate IDs did not advance in order.');
            }
            $validated[] = $id;
            $lastId = $id;
        }

        return ['ids' => $validated, 'has_more' => $hasMore, 'last_id' => $lastId];
    }

    /**
     * SQL LIKE only discovers candidates. Confirm an intact literal title, optionally followed by
     * known filename suffixes, inside explicit subject delimiters; never strip semantic title text.
     */
    private function hasLiteralPredbTitle(string $subject, string $title): bool
    {
        $literal = PredbSearchDocument::exactValue($title);
        $subject = PredbSearchDocument::exactValue($subject);
        if ($subject === $literal) {
            return true;
        }

        $opening = '(?:[\s\[({]|(?<![\p{L}\p{N}._-])(?:#34;|["\']))';
        $closing = '(?:[\s\])}]|(?:#34;|["\'])(?![\p{L}\p{N}._-]))';
        $pattern = '~(?:^|'.$opening.')('.preg_quote($literal, '~').'(?:(?!#34;)[^\s"\'\[\](){}])*)(?=$|'.$closing.')~u';
        if (preg_match_all($pattern, $subject, $matches) === false) {
            return false;
        }
        foreach ($matches[1] as $candidate) {
            $suffix = substr($candidate, strlen($literal));
            if ($this->fileNameCleaner->isKnownFileSuffix($suffix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get the update service.
     */
    public function getUpdateService(): ReleaseUpdateService
    {
        return $this->updateService;
    }

    /**
     * Get the checker service.
     */
    public function getCheckerService(): NameCheckerService
    {
        return $this->checkerService;
    }

    /**
     * Fix names using PAR2 files (requires NNTP connection).
     */
    public function fixNamesWithPar2(int $time, bool $echo, int $cats, bool $nameStatus, bool $show, NNTPService $nntp): void
    {
        $this->echoStartMessage($time, 'par2 files');
        if (! $echo || ! $nameStatus) {
            cli()->info('PAR2 downloads require --update and --set-status.');

            return;
        }

        $lock = null;
        $claimed = false;
        $outcomes = array_fill_keys(array_column(Par2RenameOutcome::cases(), 'value'), 0);
        $probes = $received = 0;
        $regularAttempted = $localSkips = $inspected = 0;
        $deadline = now()->getTimestampMs() / 1000 + max(1, (int) config('tmux.fix_names.par2_budget_seconds', 60));
        try {
            $cache = Cache::store((string) config('tmux.fix_names.cache_store', 'redis'));
            $store = $cache->getStore();
            if (! $store instanceof LockProvider) {
                throw new RuntimeException('The PAR2 rename cache store must support atomic locks.');
            }
            $key = "rename:par2:sweep:{$time}:{$cats}";
            $socketTimeout = max(
                (int) config('nntmux_nntp.socket_timeout', 120),
                (int) config('nntmux_nntp.alternate_server_socket_timeout', 120)
            );
            $lock = $store->lock($key.':lock', max(300, 2 * $socketTimeout));
            if (! $lock instanceof Lock) {
                throw new RuntimeException('The PAR2 rename cache lock must support renewal.');
            }
            $claimed = $lock->get();
            if (! $claimed) {
                cli()->info('Another PAR2 sweep is running.');

                return;
            }
            $cursor = $cache->get($key);
            $afterId = is_array($cursor) ? max(0, (int) ($cursor['after_id'] ?? 0)) : 0;
            $throughId = is_array($cursor) ? max(0, (int) ($cursor['through_id'] ?? 0)) : $this->queries->highWatermark();
            $limit = max(1, (int) config('tmux.fix_names.par2_max_releases', 300));
            $maxLocalSkips = max(0, (int) config('tmux.fix_names.par2_max_local_skips', 3000));
            $nzbContentsService = app(NzbContentsService::class);
            $nzbContentsService->setNntp($nntp);
            $firstPage = true;
            while ($regularAttempted < $limit && $inspected < PHP_INT_MAX) {
                if (now()->getTimestampMs() / 1000 >= $deadline || ! $this->refreshPar2Sweep($lock)) {
                    break;
                }
                $pageLimit = min(NameFixingQueryService::BATCH_SIZE, $limit - $regularAttempted);
                $releases = $this->queries->candidateBatch(NameFixingQueryService::SOURCE_PAR2, $time, $cats, $afterId, $pageLimit, $throughId);
                if ($releases === []) {
                    if (! $firstPage || $afterId === 0
                        || now()->getTimestampMs() / 1000 >= $deadline || ! $this->refreshPar2Sweep($lock)) {
                        break;
                    }
                    $afterId = 0;
                    $throughId = $this->queries->highWatermark();
                    $firstPage = false;

                    continue;
                }
                $firstPage = false;
                foreach ($releases as $release) {
                    if ($regularAttempted >= $limit || $inspected >= PHP_INT_MAX
                        || now()->getTimestampMs() / 1000 >= $deadline || ! $this->refreshPar2Sweep($lock)) {
                        break 2;
                    }
                    $outcome = $nzbContentsService->checkPar2Result($release->guid, (int) $release->releases_id,
                        (int) $release->groups_id, 1, (int) $show, $deadline,
                        fn (): bool => $this->refreshPar2Sweep($lock));
                    $outcomes[$outcome->value]++;
                    $stats = $nzbContentsService->par2Stats();
                    $probes += $stats['candidates_probed'];
                    $received += $stats['articles_received'];
                    $inspected++;
                    if ($outcome === Par2RenameOutcome::Retryable && $stats['candidates_probed'] === 0
                        && $stats['articles_received'] === 0 && $localSkips < $maxLocalSkips) {
                        $localSkips++;
                    } else {
                        $regularAttempted++;
                    }
                    if ($outcome === Par2RenameOutcome::Renamed) {
                        $this->updateService->fixed++;
                    }
                    $this->updateService->incrementChecked();
                    if (! $this->refreshPar2Sweep($lock)) {
                        break 2;
                    }
                    $afterId = (int) $release->releases_id;
                    $cache->forever($key, ['after_id' => $afterId, 'through_id' => $throughId]);
                }
            }
        } catch (Throwable $e) {
            Log::warning('PAR2 rename sweep stopped with work pending', ['error' => $e->getMessage()]);
            throw $e;
        } finally {
            if ($claimed && $lock !== null) {
                $lock->release();
            }
        }
        Log::info('PAR2 rename batch finished', ['time_scope' => $time, 'category_scope' => $cats,
            'outcomes' => $outcomes, 'candidates_probed' => $probes, 'articles_received' => $received,
            'local_skips' => $localSkips, 'regular_attempted' => $regularAttempted, 'inspected' => $inspected]);
        cli()->info(sprintf('PAR2 outcomes: renamed=%d no_match=%d retryable=%d deferred=%d',
            $outcomes['renamed'], $outcomes['no_match'], $outcomes['retryable'], $outcomes['deferred']));
        $this->echoFoundCount($echo, ' releases attempted');
    }

    /** @phpstan-impure */
    private function refreshPar2Sweep(Lock $lock): bool
    {
        return $lock->refresh();
    }

    /**
     * Fix XXX release names using specific file names.
     */
    public function fixXXXNamesWithFiles(int $time, bool $echo, int $cats, bool $nameStatus, bool $show): void
    {
        $this->echoStartMessage($time, 'file names');
        $type = 'Filenames, ';

        if (! $this->startBatch(NameFixingQueryService::SOURCE_XXX, $time, $cats, ' xxx file names to process.')) {
            cli()->info('Nothing to fix.');

            return;
        }

        foreach ($this->candidateBatches(NameFixingQueryService::SOURCE_XXX, $time, $cats) as $releases) {
            $files = $this->queries->groupByReleaseId(
                $this->queries->fileRows($this->releaseIds($releases))
            );

            foreach ($releases as $release) {
                $this->updateService->reset();
                $this->updateService->incrementChecked();

                $filenames = array_values(array_unique(array_map(
                    static fn (object $file): string => (string) $file->textstring,
                    $files[(int) $release->releases_id] ?? [],
                )));
                $pass = $this->collectPredbFilenamePass($filenames);
                $filenameGuard = $this->filenamePassGuard($release, $pass, 0, $filenames);
                foreach ($pass->uniqueMatches() as $selected) {
                    $candidate = clone $release;
                    $candidate->textstring = $selected['filename'];
                    $this->applyPredbFilenameMatch($candidate, $selected['match'], $echo, $type, $nameStatus, $show, $filenameGuard);
                    if ($this->updateService->matched) {
                        break;
                    }
                }

                $allLookupsComplete = $pass->complete() && ! $pass->hasMatches();
                if (! $this->updateService->matched && $allLookupsComplete) {
                    foreach ($pass->lookups as $lookup) {
                        if ($lookup['evidence']) {
                            continue;
                        }
                        $candidate = clone $release;
                        $candidate->textstring = $lookup['filename'];
                        if ($this->xxxNameCheck($candidate, $echo, $type, $nameStatus, $show, $filenameGuard)) {
                            break;
                        }
                    }
                }

                if (! $this->updateService->matched && $allLookupsComplete) {
                    $this->completeFilenamePass($release, $filenames, $echo, $nameStatus, $filenameGuard, $pass);
                }

                $this->echoRenamed($show);
            }
        }

        $this->echoFoundCount($echo, ' files');
    }

    /**
     * Fix release names using mediainfo movie_name.
     */
    public function fixNamesWithMediaMovieName(int $time, bool $echo, int $cats, bool $nameStatus, bool $show): void
    {
        $type = 'Mediainfo, ';
        $this->echoStartMessage($time, 'Mediainfo movie_name');

        if (! $this->startBatch(NameFixingQueryService::SOURCE_MEDIA_MOVIE, $time, $cats, ' mediainfo movie names to process.')) {
            cli()->info('Nothing to fix.');

            return;
        }

        foreach ($this->candidateBatches(NameFixingQueryService::SOURCE_MEDIA_MOVIE, $time, $cats) as $releases) {
            $media = $this->queries->groupByReleaseId($this->queries->mediaRows($this->releaseIds($releases)));

            foreach ($releases as $release) {
                $this->updateService->incrementChecked();
                $this->updateService->reset();

                foreach ($media[(int) $release->releases_id] ?? [] as $row) {
                    if (empty($row->movie_name)) {
                        continue;
                    }

                    $candidate = clone $release;
                    $candidate->movie_name = $row->movie_name;
                    $candidate->file_name = $row->file_name;
                    if ($this->mediaMovieNameCheck($candidate, $echo, $type, $nameStatus, $show)) {
                        break;
                    }
                }

                if (! $this->updateService->matched) {
                    $this->markProcessed($echo, $nameStatus, 'proc_uid', (int) $release->releases_id);
                }

                $this->echoRenamed($show);
            }
        }

        $this->echoFoundCount($echo, ' MediaInfo\'s');
    }

    /**
     * Check for XXX release name.
     */
    protected function xxxNameCheck(object $release, bool $echo, string $type, bool $nameStatus, bool $show, ?callable $filenameGuard = null): bool
    {
        if (preg_match('/^.+?SDPORN/i', $release->textstring, $hit)) {
            $this->updateService->updateRelease($release, $hit[0], 'fileCheck: XXX SDPORN', $echo, $type, $nameStatus, $show, 0, null, $filenameGuard);

            return $this->updateService->matched;
        }

        return false;
    }

    /**
     * Check mediainfo movie_name for release name.
     */
    protected function mediaMovieNameCheck(object $release, bool $echo, string $type, bool $nameStatus, bool $show): bool
    {
        $newName = '';

        if (! empty($release->movie_name)) {
            if (preg_match(ReleaseUpdateService::PREDB_REGEX, $release->movie_name, $hit)) {
                $newName = $hit[1];
            } elseif (preg_match('/(.+),(\sRMZ\.cr)?$/i', $release->movie_name, $hit)) {
                $newName = $hit[1];
            } else {
                $newName = $release->movie_name;
            }
        }

        if ($newName !== '') {
            if (! mb_check_encoding($newName, 'UTF-8') || mb_strlen($newName, 'UTF-8') > 255
                || preg_match('/\p{C}/u', $newName) !== 0) {
                return false;
            }
            $catalogueRows = DB::table('predb')->where('title', $newName)->limit(2)->get(['id', 'title']);
            if ($catalogueRows->isNotEmpty()) {
                if ($catalogueRows->count() !== 1) {
                    return false;
                }
                $row = $catalogueRows->first();
                if ((int) $row->id <= 0
                    || PredbSearchDocument::exactValue((string) $row->title) !== PredbSearchDocument::exactValue($newName)) {
                    return false;
                }
                $match = $this->predbMatchSelector->selectBestMatch($newName, [$row]);
                if ($match === null) {
                    return false;
                }
                $this->updateService->updateRelease($release, (string) $match['title'], 'MediaInfo: Exact PreDB Title',
                    $echo, $type, $nameStatus, $show, (int) $match['id']);

                return $this->updateService->matched;
            }
            $this->updateService->updateRelease($release, $newName, 'MediaInfo: Movie Name', $echo, $type, $nameStatus, $show, $release->predb_id ?? 0);

            return $this->updateService->matched;
        }

        return false;
    }

    /**
     * Check the array using regex for a clean name.
     *
     * @throws \Exception
     */
    public function checkName(object $release, bool $echo, string $type, bool $nameStatus, bool $show, bool $preId = false, bool $markProcessed = true): bool
    {
        if ($type === 'NFO, ') {
            return $this->processNfoCandidate(clone $release, $echo, $nameStatus, $show, ! $preId, false, $markProcessed && ! $preId);
        }

        if ($type === 'Filenames, ') {
            $this->updateService->reset();
            $hadEvidence = false;
            $lookupComplete = true;
            $inventory = $this->persistedFilenameInventory($release);
            $pass = $this->collectPredbFilenamePass([(string) $release->textstring]);
            $filenameGuard = $this->filenamePassGuard($release, $pass, 0, $inventory);
            $this->preDbFileCheck($release, $echo, $type, $nameStatus, $show, $hadEvidence, $lookupComplete, $pass, $filenameGuard);
            if (! $preId && ! $this->updateService->matched && ! $hadEvidence) {
                $result = $this->fileExtractor->extractFromFile($release->textstring);
                if ($result !== null) {
                    $this->updateService->updateRelease($release, $result->newName, 'fileCheck: '.$result->method, $echo, $type, $nameStatus, $show, 0, null, $filenameGuard);
                }
            }
            if (! $preId && $echo && $nameStatus && $markProcessed && $lookupComplete && ! $this->updateService->matched) {
                $this->completeFilenamePass($release, $inventory, $echo, $nameStatus, $filenameGuard, $pass);
            }

            return $this->updateService->matched;
        }

        $identityText = $release->textstring;

        // Check PreDB first
        $preDbMatch = $this->updateService->checkPreDbMatch($release, $identityText);
        if ($preDbMatch !== null) {
            if (PredbSearchDocument::exactValue((string) $preDbMatch['title']) === PredbSearchDocument::exactValue((string) $release->searchname)) {
                if ($echo) {
                    $this->updateService->attachPredbSnapshot($release, (int) $preDbMatch['id']);
                }
            } else {
                $this->updateService->updateRelease($release, $preDbMatch['title'], 'preDB: Match', $echo, $type, $nameStatus, $show, $preDbMatch['id']);
            }

            return $this->updateService->matched;
        }

        if ($preId) {
            return $this->updateService->matched;
        }

        // Route to appropriate checker based on type
        switch ($type) {
            case 'PAR2, ':
                $result = $this->fileExtractor->extractFromFile($release->textstring);
                if ($result !== null) {
                    $this->updateService->updateRelease($release, $result->newName, 'fileCheck: '.$result->method, $echo, $type, $nameStatus, $show);
                }
                break;

            default:
                // Use pattern checker service
                $result = $this->checkerService->check($release, $release->textstring);
                if ($result !== null) {
                    $this->updateService->updateRelease($release, $result->newName, $result->getFormattedMethod(), $echo, $type, $nameStatus, $show);
                }
        }

        // Update processing flags if not matched
        if ($echo && $nameStatus && $markProcessed && ! $this->updateService->matched) {
            $this->updateProcessingFlags($type, $release->releases_id);
        }

        return $this->updateService->matched;
    }

    /**
     * Update processing flags based on type.
     */
    protected function updateProcessingFlags(string $type, int $releaseId): void
    {
        $column = $this->processingStatusColumn($type);
        if ($column !== '') {
            $this->updateService->updateSingleColumn($column, 1, $releaseId);
        }
    }

    /**
     * Match a release filename to a PreDB filename or title.
     *
     * @throws \Exception
     */
    public function matchPreDbFiles(object $release, bool $echo, bool $nameStatus, bool $show): int
    {
        return $this->matchPreDbFileNames($release, explode('||', $release->filename ?? ''), $echo, $nameStatus, $show);
    }

    /**
     * Match independent filename observations without serializing their boundaries.
     *
     * @param  list<string>  $files
     * @param  list<string>|null  $persistedNames
     *
     * @throws \Exception
     */
    public function matchPreDbFileNames(object $release, array $files, bool $echo, bool $nameStatus, bool $show, ?array $persistedNames = null): int
    {
        $this->updateService->resetArchiveNamingDeferral();
        $inventory = $persistedNames ?? $this->persistedFilenameInventory($release);
        $pass = $this->collectPredbFilenamePass($this->filePrioritizer->prioritizeForPreDb($files), 8);
        $filenameGuard = $this->filenamePassGuard($release, $pass, 8, $inventory);
        foreach ($pass->uniqueMatches() as $selected) {
            $bestMatch = $selected['match'];
            $fileName = $selected['filename'];
            $sameTitle = PredbSearchDocument::exactValue((string) $bestMatch['title']) === PredbSearchDocument::exactValue((string) ($release->searchname ?? ''));
            $hasSnapshot = isset($release->guid, $release->name);
            if ($sameTitle && $echo) {
                if (! $this->updateService->attachPredbSnapshot($release, (int) $bestMatch['id'], (string) $bestMatch['title'], $fileName, $filenameGuard)) {
                    continue;
                }
            } elseif (! $echo && ! $hasSnapshot) {
                if ($this->updateService->currentPredbTitle((int) $bestMatch['id'], (string) $bestMatch['title'], $fileName) === null) {
                    continue;
                }
                if (! $sameTitle) {
                    $this->updateService->updateRelease($release, $bestMatch['title'], 'file matched source: '.$this->predbFilenameSourceDiagnostic((int) $bestMatch['id']), false, 'PreDB file match, ', $nameStatus, $show, (int) $bestMatch['id'], $fileName, $filenameGuard);
                    if (! $this->updateService->matched) {
                        continue;
                    }
                }
            } else {
                $this->updateService->updateRelease($release, $bestMatch['title'], 'file matched source: '.$this->predbFilenameSourceDiagnostic((int) $bestMatch['id']), $echo, 'PreDB file match, ', $nameStatus, $show, (int) $bestMatch['id'], $fileName, $filenameGuard);
                if (! $this->updateService->matched) {
                    continue;
                }
            }

            return 1;
        }

        return 0;
    }

    private function diagnosticSource(string $source): string
    {
        return (self::$useRustFfi ? $this->diagnosticSourceNative($source) : null) ?? $this->diagnosticSourcePhp($source);
    }

    private function diagnosticSourceNative(string $source): ?string
    {
        return self::queryPassNative(1, $source);
    }

    private function diagnosticSourcePhp(string $source): string
    {
        return strlen($source) <= 200 && mb_check_encoding($source, 'UTF-8')
            && mb_strlen($source, 'UTF-8') <= 50 && preg_match('/\p{C}/u', $source) === 0 ? $source : '';
    }

    private function processingStatusColumn(string $type): string
    {
        return (self::$useRustFfi ? $this->processingStatusColumnNative($type) : null) ?? $this->processingStatusColumnPhp($type);
    }

    private function processingStatusColumnNative(string $type): ?string
    {
        return self::queryPassNative(15, $type);
    }

    private function processingStatusColumnPhp(string $type): string
    {
        return match ($type) {
            'NFO, ' => 'proc_nfo', 'PAR2, ' => 'proc_par2', 'PAR2 hash, ' => 'proc_hash16k',
            'SRR, ' => 'proc_srr', 'UID, ', 'Mediainfo, ' => 'proc_uid', 'CRC32, ' => 'proc_crc32',
            default => '',
        };
    }

    /** Optional CLI context; never used to authorize or compare release identity. */
    private function predbFilenameSourceDiagnostic(int $predbId): string
    {
        try {
            $connection = DB::connection();
            if ($connection->getDriverName() !== 'sqlite' || ! $connection->getSchemaBuilder()->hasColumn('predb', 'source')) {
                return '';
            }
            $source = $connection->table('predb')->where('id', $predbId)
                ->selectRaw('substr(CAST(source AS BLOB), 1, 201) AS diagnostic_source')->value('diagnostic_source');

            return is_string($source) ? $this->diagnosticSource($source) : '';
        } catch (Throwable) {
            return '';
        }
    }

    /**
     * Check if a release name looks like a season pack (a standalone S01, Season.1 or
     * Complete.Series token and no episode marker).
     */
    public function isSeasonPack(string $name): bool
    {
        return (self::$useRustFfi ? $this->isSeasonPackNative($name) : null) ?? $this->isSeasonPackPhp($name);
    }

    private function isSeasonPackNative(string $name): ?bool
    {
        $raw = self::queryPassNative(0, $name);

        return $raw === null ? null : $raw === '1';
    }

    public function isSeasonPackPhp(string $name): bool
    {
        return SeasonPackDetector::isSeasonPackPhp($name);
    }

    /**
     * Reset the update service state.
     */
    public function reset(): void
    {
        $this->updateService->reset();
    }

    public function wasEpisodeNamingDeferredByArchiveProof(): bool
    {
        return $this->updateService->wasEpisodeNamingDeferredByArchiveProof();
    }

    /** @return array{before: array{id: int, guid: string, name: ?string, searchname: ?string}, after: array{id: int, guid: string, name: ?string, searchname: ?string}}|null */
    public function lastCommittedIdentity(): ?array
    {
        return $this->updateService->lastCommittedIdentity();
    }

    /**
     * Retrieves releases and their file names to attempt PreDB matches.
     *
     * @param  list<mixed>  $args
     *
     * @throws \Exception
     */
    public function getPreFileNames(array $args = []): void
    {
        $show = isset($args[2]) && $args[2] === 'show';
        $limited = isset($args[1]) && is_numeric($args[1]);
        $requestedLimit = $limited ? max(1, (int) $args[1]) : PHP_INT_MAX;

        cli()->info(PHP_EOL.'Match PreFiles '.($args[1] ?? 'all').' Started at '.now());
        cli()->info('Matching predb filename to cleaned release_files.name.');

        $counter = $counted = 0;
        $timeStart = now();
        $available = $this->queries->countPrefileCandidates();
        $total = min($available, $requestedLimit);
        if ($total === 0) {
            cli()->info('Nothing to do.');

            return;
        }

        cli()->info(PHP_EOL.number_format($total).' releases to process.');
        $cursor = $limited ? PHP_INT_MAX : 0;

        while ($counter < $total) {
            $batchLimit = min(NameFixingQueryService::BATCH_SIZE, $total - $counter);
            $batch = $this->queries->prefileCandidateBatch($cursor, $batchLimit, $limited);
            if ($batch === []) {
                break;
            }

            $files = $this->queries->groupByReleaseId($this->queries->fileRows($this->releaseIds($batch)));
            foreach ($batch as $row) {
                $releaseFiles = $files[(int) $row->releases_id] ?? [];
                usort($releaseFiles, static fn (object $left, object $right): int => strlen((string) $right->textstring) <=> strlen((string) $left->textstring));
                $fileNames = array_map(
                    static fn (object $file): string => (string) $file->textstring,
                    $releaseFiles
                );

                // Preserve the CLI display context; matching uses the original row list.
                $row->filename = implode('||', $fileNames);

                if ($this->matchPreDbFileNames($row, $fileNames, true, true, $show, $fileNames) === 1) {
                    $counted++;
                }
                $counter++;

                if (! $show) {
                    cli()->info('Renamed Releases: ['.number_format($counted).'] '.cli()->percentString($counter, $total));
                }
            }

            $cursor = (int) $batch[array_key_last($batch)]->releases_id;
        }

        cli()->info(PHP_EOL.'Renamed '.number_format($counted).' releases in '.now()->diffInSeconds($timeStart, true).' seconds.');
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
