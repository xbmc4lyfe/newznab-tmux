<?php

declare(strict_types=1);

namespace App\Services\Nzb;

use App\Enums\Par2RenameOutcome;
use App\Models\Release;
use App\Services\Configuration\ConfigurationProvider;
use App\Services\NfoService;
use App\Services\NNTP\NNTPService;
use App\Services\PostProcessService;
use App\Support\Par2RenameProgress;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Cache\Lock;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use JsonException;
use RuntimeException;
use SimpleXMLElement;
use Throwable;
use UnexpectedValueException;
use WeakMap;

/**
 * Service for processing NZB contents - extracting NFO files, PAR2 information,
 * and calculating release completion from NZB files.
 *
 * {@see par2Candidates()} runs in Rust (`nntmux_nzb_par2_candidates_batch` in
 * `rust/pipeline-ffi/src/predb_feeds.rs`) over the source {@see loadNzb()} parsed (or the element's `asXML()`)
 * when the pipeline library is available, and the PAR2 progress signature is hashed there too;
 * {@see par2CandidatesPhp()} remains the fallback and parity reference. {@see par2FamilyKeyNative()}
 * (`nntmux_par2_family_key`) is kept for parity tests only: one FFI call per subject did not reach the 1.5x gate.
 * {@see parseNzb()} classifies NFO/PAR2 candidates and completion in Rust straight from the NZB source
 * ({@see scanNzb()}); {@see scanNzbFilesPhp()} remains the fallback and parity reference.
 */
class NzbContentsService
{
    private const string FFI_CDEF = <<<'C'
        ssize_t nntmux_nzb_par2_candidates_batch(const char *xml, size_t xml_len, uint32_t raw, int64_t max_bytes, const char *families, size_t families_len, char *out_buf, size_t out_cap);
        ssize_t nntmux_par2_family_key(const char *subject, size_t subject_len, char *out_buf, size_t out_cap);
        ssize_t nntmux_par2_signature_sha256(const char *head, size_t head_len, const char *manifest, size_t manifest_len, const char *tail, size_t tail_len, char *out_hex);
        C;

    private static bool $useRustFfi = true;

    private static FFI|false|null $ffi = null;

    protected NzbService $nzbService;

    protected NzbParserService $parserService;

    protected NNTPService $nntp;

    protected NfoService $nfo;

    protected PostProcessService $postProcessService;

    protected bool $lookupPar2;

    protected bool $echoOutput;

    protected bool $alternateNntp = false;

    /** @var array{candidates_probed: int, articles_received: int} */
    private array $par2Stats = ['candidates_probed' => 0, 'articles_received' => 0];

    private bool $par2StructureValid = true;

    /** JSON encoding of the manifest (subject, classification and raw segment attributes per file). */
    private string $par2Manifest = '[]';

    private int $par2NonExplicitBytes = 0;

    /** Rust's JSON encoding of the last par2Candidates() result, identical to json_encode(); null after PHP. */
    private ?string $par2CandidatesJson = null;

    /** @var WeakMap<SimpleXMLElement, string>|null NZB source text of documents returned by loadNzb() */
    private ?WeakMap $nzbSources = null;

    public function __construct(
        ?NzbService $nzbService = null,
        ?NzbParserService $parserService = null,
        ?NNTPService $nntp = null,
        ?NfoService $nfo = null,
        ?PostProcessService $postProcessService = null
    ) {
        $this->echoOutput = (bool) config('nntmux.echocli');
        $this->nzbService = $nzbService ?? app(NzbService::class);
        $this->parserService = $parserService ?? app(NzbParserService::class);
        $this->nntp = $nntp ?? new NNTPService;
        $this->nfo = $nfo ?? new NfoService;
        $this->postProcessService = $postProcessService ?? app(PostProcessService::class);
        $this->lookupPar2 = (int) app(ConfigurationProvider::class)->postProcessing()->lookupPar2 === 1;
        $this->alternateNntp = (bool) config('nntmux_nntp.alternate_article_fallback');
    }

    /**
     * Look for an .nfo file in the NZB, download it, verify it, and return the content.
     *
     * @param  string  $guid  The release GUID.
     * @param  int  $relID  The release ID.
     * @param  int  $groupID  The group ID.
     * @param  string  $groupName  The group name.
     * @return string|false The verified NFO content as a string, or false if not found, download failed, or verification failed.
     *
     * @throws \Exception If NNTP operations fail.
     */
    public function getNfoFromNzb(string $guid, int $relID, int $groupID, string $groupName): string|false
    {
        // Step 1: Attempt to find a potential NFO message ID
        $messageID = $this->parseNzb($guid, $relID, $groupID, true);

        // If no NFO message ID found
        if ($messageID === false || ! isset($messageID['id'])) {
            if ($this->echoOutput) {
                echo '-';
            }
            // Make sure we set status to NFO_NONFO
            Release::query()->where('id', $relID)->update(['nfostatus' => NfoService::NFO_NONFO]);

            return false;
        }

        // Step 2: Attempt to download the potential NFO content
        $fetchedBinary = $this->nntp->getMessages($groupName, $messageID['id'], $this->alternateNntp);

        // Check if download failed
        if ($this->nntp->isError($fetchedBinary)) {
            // NFO download failed, decrement attempts to allow retries
            Release::query()->where('id', $relID)->decrement('nfostatus');
            if ($this->echoOutput) {
                echo 'f';
            }

            return false;
        }

        // Step 3: Verify if the downloaded content is actually an NFO file
        if ($this->nfo->isNFO($fetchedBinary, $guid)) {
            // NFO verification successful
            if ($this->echoOutput) {
                // Show if it was found via explicit name (+) or potentially hidden (*)
                echo $messageID['hidden'] === false ? '+' : '*';
            }

            return $fetchedBinary;
        }

        // Step 4: Handle verification failure - not a valid NFO
        if ($this->echoOutput) {
            echo '-';
        }
        Release::query()->where('id', $relID)->update(['nfostatus' => NfoService::NFO_NONFO]);

        return false;
    }

    /**
     * Gets the completion from the NZB, optionally looks if there is an NFO/PAR2 file.
     *
     * @param  string  $guid  The release GUID.
     * @param  int  $relID  The release ID.
     * @param  int  $groupID  The group ID.
     * @param  bool  $nfoCheck  Whether to specifically look for an NFO file.
     * @return array<string, mixed>|false An array containing NFO message ID and hidden status, or false if not found/error.
     *
     * @throws \Exception If NNTP operations fail.
     */
    public function parseNzb(string $guid, int $relID, int $groupID, bool $nfoCheck = false): bool|array
    {
        $scan = $this->scanNzb($guid, $nfoCheck, $this->lookupPar2);
        if ($scan === null) {
            return false;
        }
        $nfoMessageId = $scan['nfo'];

        // --- PAR2 Detection ---
        // Parse the first .par2 index file (often small, but not always 1/1) whose first segment parses.
        foreach ($scan['par2'] as $firstSegmentId) {
            if ($this->postProcessService->parsePAR2($firstSegmentId, $relID, $groupID, $this->nntp, 1) === true) {
                Release::query()->where('id', $relID)->update(['proc_par2' => 1]);
                break;
            }
        }

        $completion = $scan['completion'];

        Release::query()->where('id', $relID)->update(['completion' => $completion]);

        // If NFO check was requested, return the found message ID (prioritizing explicit)
        if ($nfoCheck && $nfoMessageId !== null && \strlen($nfoMessageId['id']) > 1) {
            return $nfoMessageId;
        }

        // If NFO check was requested but nothing suitable was found
        if ($nfoCheck && $nfoMessageId === null) {
            // Update status to indicate no NFO was found in the NZB structure
            Release::query()->where('id', $relID)->update(['nfostatus' => NfoService::NFO_NONFO]);

            return false;
        }

        // If NFO check was not requested, the function's primary goal might be just completion/PAR2 update.
        return false;
    }

    /**
     * Loads and parses an NZB file based on a GUID.
     *
     * @param  string  $guid  The release GUID to locate the NZB file
     * @return SimpleXMLElement|false The parsed NZB file as SimpleXMLElement or false on failure
     */
    public function loadNzb(string $guid): SimpleXMLElement|false
    {
        $nzbContents = $this->readNzbSource($guid);
        if ($nzbContents === false) {
            return false;
        }

        $nzb = $this->parserService->parseNzbXml($nzbContents, $this->echoOutput, $guid);
        if ($nzb !== false && self::$useRustFfi) {
            $this->nzbSources ??= new WeakMap;
            $this->nzbSources[$nzb] = $nzbContents;
        }

        return $nzb;
    }

    private function readNzbSource(string $guid): string|false
    {
        $nzbContents = $this->nzbService->readNzbContents($guid);
        if (empty($nzbContents)) {
            if ($this->echoOutput) {
                echo PHP_EOL."Unable to read NZB source for {$guid}, skipping.".PHP_EOL;
            }

            return false;
        }

        return $nzbContents;
    }

    /**
     * Attempts to get the release name from a par2 file.
     *
     * @throws \Exception
     */
    public function checkPar2(string $guid, int $relID, int $groupID, int $nameStatus, int $show): bool
    {
        return $this->checkPar2Result($guid, $relID, $groupID, $nameStatus, $show) === Par2RenameOutcome::Renamed;
    }

    public function checkPar2Result(string $guid, int $relID, int $groupID, int $nameStatus, int $show, ?float $deadline = null, ?callable $onArticleReceived = null): Par2RenameOutcome
    {
        $this->par2Stats = ['candidates_probed' => 0, 'articles_received' => 0];
        if ($nameStatus !== 1) {
            return Par2RenameOutcome::Deferred;
        }

        $lock = null;
        $claimed = false;
        try {
            $cache = Cache::store((string) config('tmux.fix_names.cache_store', 'redis'));
            $store = $cache->getStore();
            if (! $store instanceof LockProvider) {
                throw new RuntimeException('The PAR2 rename cache store must support atomic locks.');
            }
            $lease = max(600, 10 * max(
                (int) config('nntmux_nntp.socket_timeout', 120),
                (int) config('nntmux_nntp.alternate_server_socket_timeout', 120)
            ));
            $lock = $store->lock('rename:par2:'.$relID, $lease);
            if (! $lock instanceof Lock) {
                throw new RuntimeException('The PAR2 rename cache lock must support renewal.');
            }
            $claimed = $lock->get();
            if (! $claimed) {
                return Par2RenameOutcome::Deferred;
            }

            $release = Release::query()->where('id', $relID)->first();
            if ($release === null || $release->guid !== $guid || (int) $release->groups_id !== $groupID) {
                return Par2RenameOutcome::Deferred;
            }
            $snapshot = ['id' => $relID, 'guid' => $guid, 'name' => $release->name, 'searchname' => $release->searchname];
            $releaseSize = (int) ($release->size ?? 0);
            $readerOwnership = new class
            {
                public bool $held = true;
            };
            $refreshOwnership = function () use ($lock, $onArticleReceived, $readerOwnership): bool {
                return $readerOwnership->held && ($onArticleReceived === null || $onArticleReceived())
                    && $this->refreshPar2Claim($lock);
            };
            $guard = function () use ($deadline, $refreshOwnership): bool {
                return ($deadline === null || now()->getTimestampMs() / 1000 < $deadline) && $refreshOwnership();
            };
            $lastHeartbeat = 0;
            $onArticleRead = function () use ($refreshOwnership, &$lastHeartbeat, $readerOwnership): bool {
                if (! $readerOwnership->held) {
                    return false;
                }
                $now = now()->getTimestamp();
                if ($now - $lastHeartbeat < 30) {
                    return true;
                }
                $lastHeartbeat = $now;
                $readerOwnership->held = $refreshOwnership();

                return $readerOwnership->held;
            };
            if (! $this->canContinuePar2($guard)) {
                return Par2RenameOutcome::Deferred;
            }
            if ((int) $release->proc_par2 === 1 || (int) $release->isrenamed === 1 || (int) $release->predb_id > 0) {
                return $this->completePar2Snapshot($snapshot, $groupID, $guard, true) ? Par2RenameOutcome::NoMatch : Par2RenameOutcome::Deferred;
            }

            $nzbFile = $this->loadNzb($guid);
            if (! $this->canContinuePar2($guard)) {
                return Par2RenameOutcome::Deferred;
            }
            if ($nzbFile === false) {
                return Par2RenameOutcome::Retryable;
            }
            $candidates = $this->par2Candidates($nzbFile);
            $budget = max(1, (int) config('tmux.fix_names.par2_max_probes', 4));
            $maxArticleBytes = max(1, (int) config('tmux.fix_names.par2_max_probe_bytes', 2097152));
            $maxStateBytes = max(1, (int) config('tmux.fix_names.par2_max_state_bytes', 8388608));
            $alternate = $this->alternateNntp;
            $signature = $this->par2Signature(['guid' => $guid, 'group' => $groupID, 'article_limit' => $maxArticleBytes,
                'state_limit' => $maxStateBytes, 'alternate' => $alternate], $candidates);
            $progress = $this->strictPar2Progress($relID, $signature, count($candidates));
            $offset = $progress['offset'] ?? 0;
            $retry = [];
            foreach ($progress['retry_indexes'] ?? [] as $index) {
                $retry[$index] = true;
            }
            $pending = $offset < count($candidates) ? range($offset, count($candidates) - 1) : [];
            $pending = array_values(array_unique(array_merge($pending, array_keys($retry))));
            $recovery = new Par2CandidateRecovery($this->nntp, $this->postProcessService, $alternate);
            $outcome = Par2RenameOutcome::NoMatch;
            foreach ($pending as $index) {
                if ($this->par2Stats['candidates_probed'] >= $budget) {
                    $incompleteIndex = $this->hasIncompleteExplicitPar2Progress($relID, $candidates);

                    return $retry !== [] && ! $incompleteIndex ? Par2RenameOutcome::Retryable : Par2RenameOutcome::Deferred;
                }
                if (! $this->canContinuePar2($guard)) {
                    return Par2RenameOutcome::Deferred;
                }
                $candidateSnapshot = $snapshot;
                if ($releaseSize > 0) {
                    $candidateBytes = array_sum(array_column($candidates[$index]['segments'], 'bytes'));
                    $nonPar2Bytes = max(0, $this->par2NonExplicitBytes - ($candidates[$index]['explicit'] ? 0 : $candidateBytes));
                    $candidateSnapshot['max_payload_bytes'] = min($releaseSize, $nonPar2Bytes);
                }
                $result = $recovery->recover($candidates[$index], $candidateSnapshot, $signature, $groupID, $show,
                    $budget - $this->par2Stats['candidates_probed'], $maxArticleBytes, $maxStateBytes, $guard, onArticleReceived: $onArticleRead, onRead: $onArticleRead);
                $this->par2Stats['candidates_probed'] += $result->probes;
                $this->par2Stats['articles_received'] += $result->articles;
                $outcome = $result->outcome;
                if ($outcome === Par2RenameOutcome::Renamed) {
                    return $outcome;
                }
                if (! $this->canContinuePar2($guard)) {
                    return Par2RenameOutcome::Deferred;
                }
                unset($retry[$index]);
                if ($outcome === Par2RenameOutcome::Retryable || $outcome === Par2RenameOutcome::Deferred) {
                    $retry[$index] = true;
                }
                $offset = max($offset, $index + 1);
                if (! $this->savePar2Snapshot($snapshot, $groupID, $signature, $offset, array_keys($retry), $guard)) {
                    return Par2RenameOutcome::Deferred;
                }
            }
            if ($retry !== [] || ! $this->par2StructureValid || $this->hasPendingPar2Candidates($relID, $signature)) {
                return $outcome === Par2RenameOutcome::Deferred ? $outcome : Par2RenameOutcome::Retryable;
            }

            return $this->completePar2Snapshot($snapshot, $groupID, $guard, manifestSignature: $signature) ? Par2RenameOutcome::NoMatch : Par2RenameOutcome::Deferred;
        } catch (Throwable $e) {
            Log::warning('PAR2 rename attempt remains pending', ['release_id' => $relID, 'error' => $e->getMessage()]);

            return Par2RenameOutcome::Retryable;
        } finally {
            if ($claimed && $lock !== null) {
                try {
                    $lock->release();
                } catch (Throwable $e) {
                    Log::warning('Could not release PAR2 rename claim; it will expire', ['release_id' => $relID, 'error' => $e->getMessage()]);
                }
            }
        }
    }

    /** @return array{candidates_probed: int, articles_received: int} */
    public function par2Stats(): array
    {
        return $this->par2Stats;
    }

    /** @phpstan-impure */
    private function refreshPar2Claim(Lock $lock): bool
    {
        return $lock->refresh();
    }

    /**
     * @param  callable():bool  $guard
     *
     * @phpstan-impure
     */
    private function canContinuePar2(callable $guard): bool
    {
        return $guard();
    }

    /** @return array{offset:int,retry_indexes:list<int>}|null */
    private function strictPar2Progress(int $releaseId, string $signature, int $candidateCount): ?array
    {
        $row = DB::table('par2_rename_progress')->where('releases_id', $releaseId)->first();
        if ($row === null || $row->signature !== $signature) {
            return null;
        }
        $offset = filter_var($row->offset, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => $candidateCount]]);
        $retries = json_decode($row->retry_indexes, flags: JSON_THROW_ON_ERROR);
        if ($offset === false || ! is_array($retries) || ! array_is_list($retries)) {
            throw new UnexpectedValueException('Malformed PAR2 parent cursor or retry list');
        }
        $seen = [];
        $validRetries = [];
        foreach ($retries as $retry) {
            if (! is_int($retry) || $retry < 0 || $retry >= $candidateCount || isset($seen[$retry])) {
                throw new UnexpectedValueException('Malformed PAR2 parent retry identity');
            }
            $seen[$retry] = true;
            $validRetries[] = $retry;
        }

        return ['offset' => $offset, 'retry_indexes' => $validRetries];
    }

    /** @phpstan-impure */
    private function hasPendingPar2Candidates(int $releaseId, string $signature): bool
    {
        return DB::table('par2_rename_candidate_progress as candidate')
            ->join('par2_rename_progress as parent', 'parent.releases_id', '=', 'candidate.releases_id')
            ->where('candidate.releases_id', $releaseId)->where('parent.signature', $signature)->exists();
    }

    /**
     * Distinguish a partial index from a failed probe when reporting exhaustion of the budget.
     *
     * @param  list<array{subject:string,explicit:bool,segments:list<array{number:int,id:string,bytes:int}>,classification:string}>  $candidates
     */
    private function hasIncompleteExplicitPar2Progress(int $releaseId, array $candidates, ?string $familyKey = null): bool
    {
        foreach ($candidates as $candidate) {
            if ($candidate['classification'] !== 'index'
                || ($familyKey !== null && $this->par2FamilyKey($candidate['subject']) !== $familyKey)) {
                continue;
            }
            $signature = hash('sha256', json_encode([
                'subject' => $candidate['subject'],
                'explicit' => $candidate['explicit'],
                'segments' => array_map(static fn (array $segment): array => [
                    'number' => $segment['number'], 'id' => $segment['id'], 'bytes' => $segment['bytes'],
                ], $candidate['segments']),
            ], JSON_THROW_ON_ERROR));
            if (DB::table('par2_rename_candidate_progress')->where('releases_id', $releaseId)
                ->where('candidate_signature', $signature)->where('next_segment', '>', 0)
                ->whereNull('blocked_reason')->whereNotNull('accumulator_state')->exists()) {
                return true;
            }
        }

        return false;
    }

    public static function setUseRustFfi(bool $enabled): void
    {
        self::$useRustFfi = $enabled;
    }

    /**
     * sha256 of json_encode($head + ['manifest' => manifest, 'candidates' => $candidates]), built from the
     * pre-encoded manifest (and Rust's candidate JSON when par2Candidates() ran in Rust).
     *
     * @param  array<string, mixed>  $head
     * @param  list<array<string, mixed>>  $candidates
     */
    private function par2Signature(array $head, array $candidates): string
    {
        $prefix = substr(json_encode($head, JSON_THROW_ON_ERROR), 0, -1).',"manifest":';
        $suffix = ',"candidates":'.($this->par2CandidatesJson ?? json_encode($candidates, JSON_THROW_ON_ERROR)).'}';
        $ffi = self::$useRustFfi ? (self::$ffi ??= RustPipelineLibrary::resolveDomain('nzb_par2_candidates', self::FFI_CDEF) ?? false) : false;
        if ($ffi !== false) {
            try {
                $digest = $ffi->new('char[64]');
                // @phpstan-ignore method.notFound
                if ($ffi->nntmux_par2_signature_sha256($prefix, strlen($prefix), $this->par2Manifest, strlen($this->par2Manifest), $suffix, strlen($suffix), $digest) === 64) {
                    return FFI::string($digest, 64);
                }
            } catch (Throwable) {
                // Hash in PHP below.
            }
        }
        $hash = hash_init('sha256');
        hash_update($hash, $prefix);
        hash_update($hash, $this->par2Manifest);
        hash_update($hash, $suffix);

        return hash_final($hash);
    }

    private function par2FamilyKey(string $subject): ?string
    {
        $subject = preg_replace('/^\s*\[\d+\/\d+\]\s*/', '', $subject) ?? $subject;
        if (preg_match('/"([^"]+\.par2)"/i', $subject, $match) === 1) {
            $name = $match[1];
        } elseif (preg_match('/(?:^|\s)([^\s"]+\.par2)(?=\s|$)/i', $subject, $match) === 1) {
            $name = $match[1];
        } else {
            $name = trim($subject, " \t\"'");
        }
        $name = preg_replace('/\.vol\d+\+\d+\.par2$/i', '.par2', $name) ?? $name;

        return preg_match('/\.par2$/i', $name) === 1 ? $name : null;
    }

    /**
     * Rust equivalent of {@see par2FamilyKey()}; $supported is false when the library is unavailable.
     */
    public function par2FamilyKeyNative(string $subject, bool &$supported = false): ?string
    {
        $supported = false;
        $ffi = self::$ffi ??= RustPipelineLibrary::resolveDomain('nzb_par2_candidates', self::FFI_CDEF) ?? false;
        if ($ffi === false) {
            return null;
        }
        $capacity = 0;
        $scratch = RustPipelineLibrary::obtainScratch($ffi, strlen($subject) + 16, $capacity);
        if ($scratch === null) {
            return null;
        }
        // @phpstan-ignore method.notFound
        $written = $ffi->nntmux_par2_family_key($subject, strlen($subject), $scratch, $capacity);
        if (! is_int($written) || $written < -1) {
            return null;
        }
        $supported = true;

        return $written === -1 ? null : FFI::string($scratch, $written);
    }

    /**
     * @param  array{id:int,guid:string,name:?string,searchname:?string}  $snapshot
     *
     * @phpstan-impure
     */
    private function matchingPar2Snapshot(array $snapshot, int $groupId, bool $terminal = false): bool
    {
        $release = DB::table('releases')->where('id', $snapshot['id'])->lockForUpdate()->first(['guid', 'name', 'searchname', 'groups_id', 'isrenamed', 'proc_par2', 'predb_id']);
        if ($release === null || $release->guid !== $snapshot['guid'] || $release->name !== $snapshot['name'] || $release->searchname !== $snapshot['searchname'] || (int) $release->groups_id !== $groupId) {
            return false;
        }
        $completed = (int) $release->isrenamed === 1 || (int) $release->proc_par2 === 1 || (int) $release->predb_id > 0;

        return $terminal ? $completed : ((int) $release->isrenamed === 0 && (int) $release->predb_id === 0 && (int) $release->proc_par2 !== 1);
    }

    /**
     * @param  array{id:int,guid:string,name:?string,searchname:?string}  $snapshot
     * @param  list<int>  $retry
     * @param  callable():bool  $guard
     */
    private function savePar2Snapshot(array $snapshot, int $groupId, string $signature, int $offset, array $retry, callable $guard): bool
    {
        $aborted = new RuntimeException('PAR2 outer cursor lost its claim or release snapshot');
        try {
            return DB::transaction(function () use ($snapshot, $groupId, $signature, $offset, $retry, $guard, $aborted): bool {
                if (! $this->canContinuePar2($guard) || ! $this->matchingPar2Snapshot($snapshot, $groupId)
                    || ! Par2RenameProgress::save($snapshot['id'], $signature, $offset, $retry)
                    || ! $this->canContinuePar2($guard) || ! $this->matchingPar2Snapshot($snapshot, $groupId)) {
                    throw $aborted;
                }

                return true;
            });
        } catch (Throwable $exception) {
            if ($exception === $aborted) {
                return false;
            }
            throw $exception;
        }
    }

    /**
     * @param  array{id:int,guid:string,name:?string,searchname:?string}  $snapshot
     * @param  callable():bool  $guard
     */
    private function completePar2Snapshot(array $snapshot, int $groupId, callable $guard, bool $terminal = false, ?string $manifestSignature = null): bool
    {
        $aborted = new RuntimeException('PAR2 completion lost its claim or release snapshot');
        try {
            return DB::transaction(function () use ($snapshot, $groupId, $guard, $terminal, $manifestSignature, $aborted): bool {
                if (! $this->canContinuePar2($guard) || ! $this->matchingPar2Snapshot($snapshot, $groupId, $terminal)
                    || ($manifestSignature !== null && $this->hasPendingPar2Candidates($snapshot['id'], $manifestSignature))) {
                    throw $aborted;
                }
                DB::table('releases')->where('id', $snapshot['id'])->update(['proc_par2' => 1]);
                if ($manifestSignature !== null && $this->hasPendingPar2Candidates($snapshot['id'], $manifestSignature)) {
                    throw $aborted;
                }
                DB::table('par2_rename_candidate_progress')->where('releases_id', $snapshot['id'])->delete();
                DB::table('par2_rename_progress')->where('releases_id', $snapshot['id'])->delete();
                if (! $this->canContinuePar2($guard) || ! $this->matchingPar2Snapshot($snapshot, $groupId, true)) {
                    throw $aborted;
                }

                return true;
            });
        } catch (Throwable $exception) {
            if ($exception === $aborted) {
                return false;
            }
            throw $exception;
        }
    }

    /** @return list<array{subject:string,explicit:bool,segments:list<array{number:int,id:string,bytes:int}>,classification:string}> */
    private function par2Candidates(SimpleXMLElement $nzb): array
    {
        $maxBytes = max(1, (int) config('tmux.fix_names.par2_max_probe_bytes', 2097152));

        return (self::$useRustFfi ? $this->par2CandidatesNative($nzb, $maxBytes) : null) ?? $this->par2CandidatesPhp($nzb, $maxBytes);
    }

    /**
     * Rust {@see par2CandidatesPhp()} over the document's `asXML()` output, or null when PHP must decide: library
     * missing, a parser override or non-C locale (subject patterns), or a document outside the reproduced subset.
     *
     * @return list<array{subject:string,explicit:bool,segments:list<array{number:int,id:string,bytes:int}>,classification:string}>|null
     */
    private function par2CandidatesNative(SimpleXMLElement $nzb, int $maxBytes): ?array
    {
        if ($this->parserService::class !== NzbParserService::class
            || ! in_array(setlocale(LC_CTYPE, '0'), ['C', 'POSIX', 'C.UTF-8', 'C.utf8'], true)) {
            return null;
        }
        $ffi = self::$ffi ??= RustPipelineLibrary::resolveDomain('nzb_par2_candidates', self::FFI_CDEF) ?? false;
        if ($ffi === false) {
            return null;
        }
        $source = $this->nzbSources[$nzb] ?? null;
        $raw = $source !== null;
        $output = $raw ? $this->par2CandidatesCall($ffi, $source, true, $maxBytes) : null;
        if ($output === null) {
            // The source scan declines what libxml normalizes (such as literal tabs in attribute values); the
            // serialization does not. A child element serializes without its document's encoding declaration.
            $source = $nzb->asXML();
            $raw = false;
            if (! is_string($source) || ! str_starts_with($source, '<?xml')) {
                return null;
            }
            $output = $this->par2CandidatesCall($ffi, $source, false, $maxBytes);
        }
        if ($output !== null && $output[0] === 'F') {
            // Family keys outside Rust's lowercase table: repeat the scan with mb_strtolower's answers.
            $lower = implode("\0", array_map(mb_strtolower(...), explode("\0", substr($output, 1))));
            $output = $this->par2CandidatesCall($ffi, $source, $raw, $maxBytes, $lower);
        }
        if ($output === null || $output[0] === 'F') {
            return null;
        }
        $parts = explode("\n", $output);
        if (count($parts) !== 3) {
            return null;
        }
        try {
            /** @var list<array{subject:string,explicit:bool,segments:list<array{number:int,id:string,bytes:int}>,classification:string}> $candidates */
            $candidates = json_decode($parts[1], true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }
        $this->par2Manifest = $parts[0];
        $this->par2CandidatesJson = $parts[1];
        $this->par2StructureValid = $parts[2][0] === '1';
        $this->par2NonExplicitBytes = (int) substr($parts[2], 1);

        return $candidates;
    }

    /** Output of `nntmux_nzb_par2_candidates_batch`, or null when Rust declined the document. */
    private function par2CandidatesCall(FFI $ffi, string $xml, bool $raw, int $maxBytes, string $families = ''): ?string
    {
        $length = strlen($xml);
        $capacity = 0;
        $scratch = RustPipelineLibrary::obtainScratch($ffi, $length + 4096, $capacity);
        if ($scratch === null) {
            return null;
        }
        // @phpstan-ignore method.notFound
        $written = $ffi->nntmux_nzb_par2_candidates_batch($xml, $length, (int) $raw, $maxBytes, $families, strlen($families), $scratch, $capacity);
        if (is_int($written) && $written < -1) {
            $scratch = RustPipelineLibrary::obtainScratch($ffi, -$written, $capacity);
            if ($scratch === null) {
                return null;
            }
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_nzb_par2_candidates_batch($xml, $length, (int) $raw, $maxBytes, $families, strlen($families), $scratch, $capacity);
        }

        return is_int($written) && $written > 0 ? FFI::string($scratch, $written) : null;
    }

    /** @return list<array{subject:string,explicit:bool,segments:list<array{number:int,id:string,bytes:int}>,classification:string}> */
    private function par2CandidatesPhp(SimpleXMLElement $nzb, int $maxBytes): array
    {
        $explicit = $volumes = $firstVolumes = $matchingVolumes = $indexFamilies = $families = $legacy = $hidden = $manifest = [];
        $this->par2CandidatesJson = null;
        $this->par2NonExplicitBytes = 0;
        $this->par2StructureValid = count($nzb->file) > 0;
        foreach ($nzb->file as $file) {
            $subject = (string) $file->attributes()->subject;
            $segments = $raw = [];
            $valid = count($file->segments->segment) > 0;
            foreach ($file->segments->segment as $entry) {
                $raw[] = ['number' => (string) $entry->attributes()->number, 'id' => (string) $entry, 'bytes' => (string) $entry->attributes()->bytes];
                $number = filter_var((string) $entry->attributes()->number, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                $bytes = filter_var((string) $entry->attributes()->bytes, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                $id = trim((string) $entry);
                $valid = $valid && $number !== false && $bytes !== false && $id !== '';
                $segments[] = ['number' => $number === false ? 0 : $number, 'id' => $id, 'bytes' => $bytes === false ? 0 : $bytes];
            }
            usort($segments, static fn (array $left, array $right): int => $left['number'] <=> $right['number']);
            foreach ($segments as $index => $segment) {
                if ($segment['number'] !== $index + 1) {
                    $valid = false;
                }
            }
            $isExplicit = preg_match('/\.par2(?:["\s]|$)/i', $subject) === 1;
            if (! $isExplicit) {
                foreach ($segments as $segment) {
                    $this->par2NonExplicitBytes += $segment['bytes'];
                }
            }
            $classification = $isExplicit ? (preg_match('/\.vol\d+\+\d+\.par2(?:["\s]|$)/i', $subject) === 1 ? 'volume' : 'index') : 'excluded';
            $singlePart = count($segments) === 1 && $this->parserService->extractPartsTotal($subject) <= 1;
            $knownContent = preg_match('/\.(?:rar|r\d{2,4}|\d{3}|7z|zip|mkv|mp4|avi|m2ts|ts|iso|nfo|sfv|srr|srs|jpg|png|mp3|flac)(?:["\s]|$)/i', $subject) === 1;
            if (! $isExplicit && (! $knownContent || $singlePart) && $segments !== []) {
                if (! $valid || $segments[0]['bytes'] <= $maxBytes) {
                    $classification = $singlePart && preg_match('/\.(par[" ]|\d{2,3}").+\(1\/1\)/i', $subject) === 1 ? 'legacy' : 'hidden';
                }
            }
            $manifest[] = ['subject' => $subject, 'classification' => $classification, 'segments' => $raw];
            if (! $valid && $classification !== 'excluded') {
                $this->par2StructureValid = false;
            }
            if ($classification === 'excluded' || $segments === []) {
                continue;
            }
            if (! $valid) {
                $segments[0]['number'] = 0;
            }
            $candidate = ['subject' => $subject, 'explicit' => $isExplicit, 'segments' => $segments, 'classification' => $classification];
            $key = hash('sha256', json_encode($candidate, JSON_THROW_ON_ERROR));
            match ($classification) {
                'index' => $explicit[$key] = $candidate,
                'volume' => $volumes[$key] = $candidate,
                'legacy' => $legacy[$key] = $candidate,
                default => $hidden[$key] = $candidate,
            };
            if ($classification === 'index') {
                $indexFamilies[mb_strtolower($this->par2FamilyKey($subject) ?? $key)] = true;
            }
            if ($classification === 'volume') {
                $family = mb_strtolower($this->par2FamilyKey($subject) ?? $key);
                if (! isset($families[$family])) {
                    $families[$family] = true;
                    $firstVolumes[$key] = $candidate;
                }
            }
        }
        $this->par2Manifest = json_encode($manifest, JSON_THROW_ON_ERROR);
        uasort($hidden, static fn (array $left, array $right): int => $left['segments'][0]['bytes'] <=> $right['segments'][0]['bytes']);

        foreach ($firstVolumes as $key => $candidate) {
            $family = mb_strtolower($this->par2FamilyKey($candidate['subject']) ?? $key);
            if (isset($indexFamilies[$family])) {
                $matchingVolumes[$key] = $candidate;
            }
        }

        // Family matches affect priority only; standalone and later volumes remain resumable.
        return array_values($explicit + $matchingVolumes + $legacy + $hidden + $firstVolumes + $volumes);
    }

    /**
     * Load the NZB and classify its files for {@see parseNzb()}: segment totals, completion, the NFO
     * candidate (explicit names win over the first hidden candidate) and, when $par2 is set and a
     * non-PAR2 file exists, the first-segment ids of PAR2 index files in document order. Null when the
     * NZB cannot be read or parsed.
     *
     * Rust (`nntmux_nzb_classify_nfo_par2_packed`) scans the source directly when the library is
     * available, skipping the SimpleXML parse; documents it declines take {@see loadNzb()} and
     * {@see scanNzbFilesPhp()}, which remain the fallback and parity reference. Subclasses (which may
     * override loading or completion) and custom parsers keep the PHP path.
     *
     * @return array{files:int,actual:int,artificial:int,completion:float,nfo:array{hidden:bool,id:string,priority:int}|null,par2:list<string>}|null
     */
    protected function scanNzb(string $guid, bool $nfoCheck, bool $par2): ?array
    {
        if (! self::$useRustFfi || static::class !== self::class || $this->parserService::class !== NzbParserService::class) {
            $nzbFile = $this->loadNzb($guid);

            return $nzbFile === false ? null : $this->scanNzbFilesPhp($nzbFile, $nfoCheck, $par2);
        }
        $nzbContents = $this->readNzbSource($guid);
        if ($nzbContents === false) {
            return null;
        }
        $scan = $this->scanNzbFilesNative($nzbContents, $nfoCheck, $par2);
        if ($scan !== null) {
            // parseNzbXml() leaves libxml's internal error handling enabled; keep that process state.
            libxml_use_internal_errors(true);

            return $scan;
        }
        $nzbFile = $this->parserService->parseNzbXml($nzbContents, $this->echoOutput, $guid);

        return $nzbFile === false ? null : $this->scanNzbFilesPhp($nzbFile, $nfoCheck, $par2);
    }

    /**
     * @return array{files:int,actual:int,artificial:int,completion:float,nfo:array{hidden:bool,id:string,priority:int}|null,par2:list<string>}
     */
    public function scanNzbFilesPhp(SimpleXMLElement $nzbFile, bool $nfoCheck, bool $par2): array
    {
        $files = $actualParts = $artificialParts = 0;
        // Initialize NFO flags based on whether we are checking for NFOs.
        $foundNFO = $hiddenNFO = $nfoCheck === false;
        $nfoMessageId = null; // Store potential NFO message ID here
        $par2Ids = [];
        $hasNonPar2File = false;
        foreach ($nzbFile->file as $nzbContents) {
            if (preg_match('/\.par2(?:["\s]|$)/i', (string) $nzbContents->attributes()->subject) !== 1) {
                $hasNonPar2File = true;
                break;
            }
        }

        foreach ($nzbFile->file as $nzbContents) {
            $files++;
            $segmentCountInFile = 0;
            $firstSegmentId = null; // Initialize here for each file
            foreach ($nzbContents->segments->segment as $segment) {
                $actualParts++;
                $segmentCountInFile++;
                // Store the first segment ID of the current file, potentially useful for NFO/PAR2
                if ($segmentCountInFile === 1) {
                    $firstSegmentId = (string) $segment;
                }
            }

            $subject = (string) $nzbContents->attributes()->subject;

            // Calculate artificial parts from subject
            $artificialParts += $this->parserService->extractPartsTotal($subject);

            // --- NFO Detection ---
            // Check for explicit NFO files first (with enhanced patterns)
            if (! $foundNFO && isset($firstSegmentId)) {
                $nfoDetection = $this->parserService->detectNfoFile($subject);
                if ($nfoDetection !== false) {
                    $nfoMessageId = ['hidden' => $nfoDetection['hidden'], 'id' => $firstSegmentId, 'priority' => $nfoDetection['priority']];
                    $foundNFO = true;
                }
            }

            // Check for potential "hidden" NFOs with improved detection
            // Only consider this if an explicit NFO wasn't found yet
            if (! $foundNFO && ! $hiddenNFO && isset($firstSegmentId)) {
                $hiddenNfoDetection = $this->parserService->detectHiddenNfoFile($subject, $segmentCountInFile);
                if ($hiddenNfoDetection !== false) {
                    $nfoMessageId = ['hidden' => $hiddenNfoDetection['hidden'], 'id' => $firstSegmentId, 'priority' => $hiddenNfoDetection['priority']];
                    $hiddenNFO = true;
                }
            }

            // Look specifically for the .par2 index file; parseNzb() parses them in this order.
            if ($par2 && $hasNonPar2File && isset($firstSegmentId) && $this->parserService->detectPar2IndexFile($subject)) {
                $par2Ids[] = $firstSegmentId;
            }
        }

        return [
            'files' => $files,
            'actual' => $actualParts,
            'artificial' => $artificialParts,
            'completion' => $this->calculateCompletion($actualParts, $artificialParts),
            'nfo' => $nfoMessageId,
            'par2' => $par2Ids,
        ];
    }

    /**
     * Rust {@see scanNzbFilesPhp()} over the NZB source, or null when PHP must decide (library missing,
     * non-C locale, or a document outside the strict subset the scanner reproduces).
     *
     * @return array{files:int,actual:int,artificial:int,completion:float,nfo:array{hidden:bool,id:string,priority:int}|null,par2:list<string>}|null
     */
    public function scanNzbFilesNative(string $source, bool $nfoCheck, bool $par2): ?array
    {
        $ffi = NzbParserService::nfoCandidateFfi();
        if ($ffi === null) {
            return null;
        }
        $output = self::classifyCall($ffi, 'nntmux_nzb_classify_nfo_par2_packed', $source, (int) $nfoCheck | ($par2 ? 2 : 0));

        return $output === null ? null : self::decodeScan($output, null);
    }

    /**
     * Rust batch over subjects and segment counts collected from SimpleXML
     * (`nntmux_nzb_classify_nfo_par2_batch_nul`). Kept for parity tests: the SimpleXML walk it still
     * needs dominates, so it does not reach the 1.5x gate.
     *
     * @return array{files:int,actual:int,artificial:int,completion:float,nfo:array{hidden:bool,id:string,priority:int}|null,par2:list<string>}|null
     */
    public function scanNzbFilesBatchNative(SimpleXMLElement $nzbFile, bool $nfoCheck, bool $par2): ?array
    {
        $ffi = NzbParserService::nfoCandidateFfi();
        if ($ffi === null) {
            return null;
        }
        $records = '';
        $firstIds = [];
        foreach ($nzbFile->file as $file) {
            $count = 0;
            $first = '';
            foreach ($file->segments->segment as $segment) {
                if ($count++ === 0) {
                    $first = (string) $segment;
                }
            }
            $subject = (string) $file->attributes()->subject;
            if (str_contains($subject, "\0")) {
                return null;
            }
            $records .= $subject."\0".$count."\0";
            $firstIds[] = $first;
        }
        $output = self::classifyCall($ffi, 'nntmux_nzb_classify_nfo_par2_batch_nul', $records, (int) $nfoCheck | ($par2 ? 2 : 0));

        return $output === null ? null : self::decodeScan($output, $firstIds);
    }

    private static function classifyCall(FFI $ffi, string $function, string $input, int $flags): ?string
    {
        $length = \strlen($input);
        $capacity = 0;
        $scratch = RustPipelineLibrary::obtainScratch($ffi, 4096, $capacity);
        if ($scratch === null) {
            return null;
        }
        $written = $ffi->$function($input, $length, $flags, $scratch, $capacity);
        if (\is_int($written) && $written < -1) {
            $scratch = RustPipelineLibrary::obtainScratch($ffi, -$written, $capacity);
            if ($scratch === null) {
                return null;
            }
            $written = $ffi->$function($input, $length, $flags, $scratch, $capacity);
        }

        return \is_int($written) && $written > 0 ? FFI::string($scratch, $written) : null;
    }

    /**
     * Decode the scanner record; $firstIds maps file indexes to ids for the batch variant.
     *
     * @param  list<string>|null  $firstIds
     * @return array{files:int,actual:int,artificial:int,completion:float,nfo:array{hidden:bool,id:string,priority:int}|null,par2:list<string>}|null
     */
    private static function decodeScan(string $output, ?array $firstIds): ?array
    {
        /** @var array{files:int,actual:int,artificial:int,completion:float,kind:int,priority:int,index:int,length:int}|false $head */
        $head = unpack('Pfiles/Pactual/Partificial/ecompletion/Ckind/Cpriority/Vindex/Vlength', $output);
        if ($head === false) {
            return null;
        }
        $offset = 42;
        $nfo = null;
        if ($head['kind'] !== 0) {
            $nfo = ['hidden' => $head['kind'] === 2, 'id' => $firstIds === null ? substr($output, $offset, $head['length']) : $firstIds[$head['index']], 'priority' => $head['priority']];
        }
        $offset += $head['length'];
        $count = unpack('V', $output, $offset)[1] ?? 0;
        $offset += 4;
        $par2 = [];
        for ($i = 0; $i < $count; $i++) {
            [, $index, $length] = unpack('V2', $output, $offset) ?: [0, 0, 0];
            $offset += 8;
            $par2[] = $firstIds === null ? substr($output, $offset, $length) : $firstIds[$index];
            $offset += $length;
        }

        return [
            'files' => $head['files'],
            'actual' => $head['actual'],
            'artificial' => $head['artificial'],
            'completion' => $head['completion'],
            'nfo' => $nfo,
            'par2' => $par2,
        ];
    }

    /**
     * Calculate the completion percentage from actual and expected parts.
     *
     * @param  int  $actualParts  The actual number of parts found
     * @param  int  $artificialParts  The expected number of parts from subject
     * @return float The completion percentage (0-100)
     */
    protected function calculateCompletion(int $actualParts, int $artificialParts): float
    {
        // Avoid division by zero and handle cases where parts info might be missing/incorrect
        if ($artificialParts > 0) {
            return min(100, ($actualParts / $artificialParts) * 100);
        } elseif ($actualParts > 0) {
            // If artificial parts couldn't be determined, but we have actual parts,
            // we can't calculate completion accurately based on subject.
            return 0;
        }

        // If both are zero (e.g., empty NZB or parsing issue), completion is 0.
        return 0;
    }

    /**
     * Set NNTP service instance.
     */
    public function setNntp(NNTPService $nntp): void
    {
        $this->nntp = $nntp;
    }

    /**
     * Set NFO handler instance.
     */
    public function setNfo(NfoService $nfo): void
    {
        $this->nfo = $nfo;
    }

    /**
     * Set echo output setting.
     */
    public function setEchoOutput(bool $echo): void
    {
        $this->echoOutput = $echo;
    }

    /**
     * Get echo output setting.
     */
    public function getEchoOutput(): bool
    {
        return $this->echoOutput;
    }
}
