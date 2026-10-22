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
use Illuminate\Cache\Lock;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use SimpleXMLElement;
use Throwable;
use UnexpectedValueException;

/**
 * Service for processing NZB contents - extracting NFO files, PAR2 information,
 * and calculating release completion from NZB files.
 */
class NzbContentsService
{
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

    /** @var list<array<string, mixed>> */
    private array $par2Manifest = [];

    private int $par2NonExplicitBytes = 0;

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
        $nzbFile = $this->loadNzb($guid);
        if ($nzbFile === false) {
            return false;
        }

        $messageID = $hiddenID = '';
        $actualParts = $artificialParts = 0;
        // Initialize foundPAR2 based on settings; if lookupPar2 is false, we don't need to find one.
        $foundPAR2 = $this->lookupPar2 === false;
        // Initialize NFO flags based on whether we are checking for NFOs.
        $foundNFO = $hiddenNFO = $nfoCheck === false;
        $nfoMessageId = null; // Store potential NFO message ID here
        $hasNonPar2File = false;
        foreach ($nzbFile->file as $nzbContents) {
            if (preg_match('/\.par2(?:["\s]|$)/i', (string) $nzbContents->attributes()->subject) !== 1) {
                $hasNonPar2File = true;
                break;
            }
        }

        foreach ($nzbFile->file as $nzbContents) {
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
            if ($nfoCheck && ! $foundNFO && isset($firstSegmentId)) {
                $nfoDetection = $this->parserService->detectNfoFile($subject);
                if ($nfoDetection !== false) {
                    $nfoMessageId = ['hidden' => $nfoDetection['hidden'], 'id' => $firstSegmentId, 'priority' => $nfoDetection['priority']];
                    $foundNFO = true;
                }
            }

            // Check for potential "hidden" NFOs with improved detection
            // Only consider this if an explicit NFO wasn't found yet
            if ($nfoCheck && ! $foundNFO && ! $hiddenNFO && isset($firstSegmentId)) {
                $hiddenNfoDetection = $this->parserService->detectHiddenNfoFile($subject, $segmentCountInFile);
                if ($hiddenNfoDetection !== false) {
                    $nfoMessageId = ['hidden' => $hiddenNfoDetection['hidden'], 'id' => $firstSegmentId, 'priority' => $hiddenNfoDetection['priority']];
                    $hiddenNFO = true;
                }
            }

            // --- PAR2 Detection ---
            // Look specifically for the .par2 index file (often small, but not always 1/1)
            if ($this->lookupPar2 && $hasNonPar2File && ! $foundPAR2 && isset($firstSegmentId) && $this->parserService->detectPar2IndexFile($subject)) {
                // Attempt to parse the PAR2 file using its first segment ID
                if ($this->postProcessService->parsePAR2($firstSegmentId, $relID, $groupID, $this->nntp, 1) === true) {
                    Release::query()->where('id', $relID)->update(['proc_par2' => 1]);
                    $foundPAR2 = true;
                }
            }
        } // End foreach $nzbFile->file

        // Calculate completion
        $completion = $this->calculateCompletion($actualParts, $artificialParts);

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
        $nzbContents = $this->nzbService->readNzbContents($guid);
        if (empty($nzbContents)) {
            if ($this->echoOutput) {
                echo PHP_EOL."Unable to read NZB source for {$guid}, skipping.".PHP_EOL;
            }

            return false;
        }

        return $this->parserService->parseNzbXml($nzbContents, $this->echoOutput, $guid);
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
            $signature = hash('sha256', json_encode(['guid' => $guid, 'group' => $groupID, 'article_limit' => $maxArticleBytes,
                'state_limit' => $maxStateBytes, 'alternate' => $alternate, 'manifest' => $this->par2Manifest, 'candidates' => $candidates], JSON_THROW_ON_ERROR));
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
        $explicit = $volumes = $firstVolumes = $matchingVolumes = $indexFamilies = $families = $legacy = $hidden = [];
        $this->par2Manifest = [];
        $this->par2NonExplicitBytes = 0;
        $this->par2StructureValid = count($nzb->file) > 0;
        $maxBytes = max(1, (int) config('tmux.fix_names.par2_max_probe_bytes', 2097152));
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
            $this->par2Manifest[] = ['subject' => $subject, 'classification' => $classification, 'segments' => $raw];
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
