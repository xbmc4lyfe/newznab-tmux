<?php

declare(strict_types=1);

namespace App\Services\Nzb;

use App\Enums\Par2RenameOutcome;
use App\Models\UsenetGroup;
use App\Services\NNTP\NNTPService;
use App\Services\Par2PacketAccumulator;
use App\Services\Par2Processor;
use App\Services\PostProcessService;
use App\Services\Yenc\YencPart;
use App\Support\Par2CandidateCheckpoint;
use App\Support\Par2CandidateProgress;
use dariusiii\rarinfo\Par2Info;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LengthException;
use Throwable;

/** Reconstruct one ordered candidate without downloading committed articles again. */
final class Par2CandidateRecovery
{
    public function __construct(private readonly NNTPService $nntp, private readonly PostProcessService $postprocess, private readonly bool $alternate = false) {}

    /**
     * @param  array{subject:string,explicit:bool,segments:list<array{number:int,id:string,bytes:int}>}  $candidate
     * @param  array{id:int,guid:string,name:?string,searchname:?string,max_payload_bytes?:int}  $snapshot
     * @param  callable():bool  $guard
     * @param  null|callable():bool  $onArticleReceived
     * @param  null|callable():bool  $onRead
     */
    public function recover(array $candidate, array $snapshot, string $manifestSignature, int $groupId, int $show, int $articleBudget, int $maxArticleBytes, int $maxReleaseStateBytes, callable $guard, ?callable $onArticleReceived = null, ?callable $onRead = null): Par2CandidateRecoveryResult
    {
        $probes = $articles = 0;
        $result = static fn (Par2RenameOutcome $outcome): Par2CandidateRecoveryResult => new Par2CandidateRecoveryResult($outcome, $probes, $articles);
        if (! self::owns($guard)) {
            return $result(Par2RenameOutcome::Deferred);
        }
        $segmentCount = count($candidate['segments']);
        if ($segmentCount < 1 || $articleBudget < 0 || $maxArticleBytes < 1 || $maxReleaseStateBytes < 1) {
            throw new InvalidArgumentException('Invalid PAR2 recovery limits or empty candidate');
        }
        $signature = hash('sha256', json_encode([
            'subject' => $candidate['subject'], 'explicit' => $candidate['explicit'],
            'segments' => array_map(static fn (array $segment): array => ['number' => $segment['number'], 'id' => $segment['id'], 'bytes' => $segment['bytes']], $candidate['segments']),
        ], JSON_THROW_ON_ERROR));
        $cursor = $decodedOffset = 0;
        $identity = null;
        $inventory = null;
        $checkpoint = null;
        try {
            try {
                $checkpoint = Par2CandidateProgress::load($snapshot['id'], $manifestSignature, $signature, $segmentCount, $maxReleaseStateBytes);
            } catch (Throwable) {
                if (! self::owns($guard)) {
                    return $result(Par2RenameOutcome::Deferred);
                }
                Par2CandidateProgress::blockCorruptCandidate($snapshot, $manifestSignature, $signature, $segmentCount, $maxReleaseStateBytes, $guard);

                return $result(self::owns($guard) ? Par2RenameOutcome::Retryable : Par2RenameOutcome::Deferred);
            }
            if (! self::owns($guard)) {
                return $result(Par2RenameOutcome::Deferred);
            }
            if (self::isBlocked($checkpoint)) {
                return $result(Par2RenameOutcome::Retryable);
            }
            if ($checkpoint !== null) {
                $cursor = $checkpoint->nextSegment;
                $decodedOffset = $checkpoint->decodedOffset;
                $identity = $checkpoint->articleIdentity;
                $inventory = Par2PacketAccumulator::fromState($checkpoint->accumulatorState ?? [], $maxReleaseStateBytes);
            } else {
                try {
                    $inventory = new Par2PacketAccumulator($maxReleaseStateBytes);
                } catch (LengthException) {
                    Par2CandidateProgress::save($snapshot, $manifestSignature, $signature, 0, [], null, $segmentCount, $maxReleaseStateBytes, $guard, 'state_limit');

                    return $result(self::owns($guard) ? Par2RenameOutcome::Retryable : Par2RenameOutcome::Deferred);
                }
            }

            $save = function (?string $block = null, bool $tombstone = false) use ($snapshot, $manifestSignature, $signature, $segmentCount, $maxReleaseStateBytes, $guard, &$cursor, &$decodedOffset, &$identity, &$inventory, &$checkpoint): bool {
                if (! self::owns($guard) || ! Par2CandidateProgress::save($snapshot, $manifestSignature, $signature, $cursor, [], $tombstone ? null : $inventory->exportState(), $segmentCount, $maxReleaseStateBytes, $guard, $block, $tombstone ? 0 : $decodedOffset, $tombstone ? null : $identity) || ! self::owns($guard)) {
                    return false;
                }
                $checkpoint = Par2CandidateProgress::load($snapshot['id'], $manifestSignature, $signature, $segmentCount, $maxReleaseStateBytes);

                return self::owns($guard) && $checkpoint !== null;
            };
            $finish = function (Par2RenameOutcome $outcome) use ($snapshot, $manifestSignature, $signature, $guard, $groupId, &$checkpoint): Par2RenameOutcome {
                if ($outcome === Par2RenameOutcome::Renamed) {
                    if (self::owns($guard) && $checkpoint !== null) {
                        Par2CandidateProgress::deleteCandidate($snapshot, $manifestSignature, $signature, $guard);
                    }

                    return $outcome;
                }
                if (! self::owns($guard) || ! self::snapshotCurrent($snapshot, $groupId) || ($checkpoint !== null && ! Par2CandidateProgress::deleteCandidate($snapshot, $manifestSignature, $signature, $guard)) || ! self::owns($guard)) {
                    return Par2RenameOutcome::Deferred;
                }

                return $outcome;
            };
            $apply = function () use ($snapshot, $groupId, $show, $guard, $segmentCount, &$cursor, &$inventory, &$identity, &$decodedOffset): Par2RenameOutcome {
                if (! self::owns($guard)) {
                    return Par2RenameOutcome::Deferred;
                }
                $maxPayloadBytes = isset($snapshot['max_payload_bytes']) ? (int) $snapshot['max_payload_bytes'] : null;
                $fileComplete = $identity === null || $decodedOffset === $identity['size'];
                $terminal = ($cursor === $segmentCount && $fileComplete && $inventory->isComplete())
                    || ($inventory->hasCompleteMainInventory() && $inventory->exportState()['skip_remaining'] > 0)
                    || ($cursor > 0 && $maxPayloadBytes !== null && $maxPayloadBytes <= 0)
                    || Par2Processor::exceedsPayloadBudget($inventory, $maxPayloadBytes);
                $outcome = $this->postprocess->applyPAR2InventoryForRename($inventory, $snapshot['id'], $groupId, $show, $guard, $terminal, $snapshot);
                if ($outcome === Par2RenameOutcome::Renamed) {
                    return $outcome;
                }
                if (! self::owns($guard)) {
                    return Par2RenameOutcome::Deferred;
                }

                return $outcome === Par2RenameOutcome::NoMatch && ! $terminal ? Par2RenameOutcome::Retryable : $outcome;
            };
            $group = UsenetGroup::getNameByID($groupId);
            if ($group === '' || ! self::owns($guard)) {
                return $result(self::owns($guard) ? Par2RenameOutcome::Retryable : Par2RenameOutcome::Deferred);
            }
            if ($checkpoint !== null) {
                $outcome = $apply();
                if ($outcome !== Par2RenameOutcome::Retryable) {
                    return $result($outcome === Par2RenameOutcome::Deferred ? $outcome : $finish($outcome));
                }
            }
            while ($cursor < $segmentCount) {
                if (! self::owns($guard)) {
                    return new Par2CandidateRecoveryResult(Par2RenameOutcome::Deferred, $probes, $articles);
                }
                if ($probes >= $articleBudget) {
                    $saved = $save();

                    return new Par2CandidateRecoveryResult(! $saved ? Par2RenameOutcome::Deferred : (self::isBlocked($checkpoint) ? Par2RenameOutcome::Retryable : Par2RenameOutcome::Deferred), $probes, $articles);
                }
                $segment = $candidate['segments'][$cursor];
                if ($segment['bytes'] < 1 || $segment['bytes'] > $maxArticleBytes || $segment['number'] < 1 || $segment['id'] === '') {
                    $saved = $save('article_limit');

                    return new Par2CandidateRecoveryResult($saved ? Par2RenameOutcome::Retryable : Par2RenameOutcome::Deferred, $probes, $articles);
                }
                if (! self::owns($guard)) {
                    return new Par2CandidateRecoveryResult(Par2RenameOutcome::Deferred, $probes, $articles);
                }
                $probes++;
                $article = $this->nntp->getMessageBounded($group, $segment['id'], $maxArticleBytes, $this->alternate, $onRead);
                if ($article->body !== null) {
                    $articles++;
                }
                if ($onArticleReceived !== null && ! $onArticleReceived()) {
                    return new Par2CandidateRecoveryResult(Par2RenameOutcome::Deferred, $probes, $articles);
                }
                if (! self::owns($guard)) {
                    return new Par2CandidateRecoveryResult(Par2RenameOutcome::Deferred, $probes, $articles);
                }
                if ($article->body === null) {
                    $saved = $save($article->oversized ? 'article_limit' : null);

                    return new Par2CandidateRecoveryResult($saved ? Par2RenameOutcome::Retryable : Par2RenameOutcome::Deferred, $probes, $articles);
                }
                if (strlen($article->body) > $maxArticleBytes) {
                    $saved = $save('article_limit');

                    return new Par2CandidateRecoveryResult($saved ? Par2RenameOutcome::Retryable : Par2RenameOutcome::Deferred, $probes, $articles);
                }
                try {
                    $nextIdentity = $this->validatePart($article->part, $article->body, $segment['number'], $segmentCount, $decodedOffset, $identity);
                    $body = $article->body;
                    if ($cursor === 0 && $decodedOffset === 0) {
                        $marker = strpos($body, Par2Info::PACKET_MARKER);
                        $partialMarker = strlen($body) < 8 && $body !== '' && str_starts_with(Par2Info::PACKET_MARKER, $body);
                        if ($marker === false && ! $partialMarker && ! $candidate['explicit']) {
                            return new Par2CandidateRecoveryResult($finish(Par2RenameOutcome::NoMatch), $probes, $articles);
                        }
                        if ($marker !== false && $marker > 0) {
                            $body = substr($body, $marker);
                        }
                    }
                    if ($body === '' || strlen($article->body) > PHP_INT_MAX - $decodedOffset) {
                        throw new InvalidArgumentException('Empty or overflowing PAR2 article');
                    }
                    $inventory->append($body);
                } catch (LengthException) {
                    $saved = $save('state_limit', true);

                    return new Par2CandidateRecoveryResult($saved ? Par2RenameOutcome::Retryable : Par2RenameOutcome::Deferred, $probes, $articles);
                } catch (InvalidArgumentException) {
                    $saved = $save('malformed_article');

                    return new Par2CandidateRecoveryResult($saved ? Par2RenameOutcome::Retryable : Par2RenameOutcome::Deferred, $probes, $articles);
                }
                $decodedOffset += strlen($article->body);
                $identity = $nextIdentity;
                $cursor++;
                if (! $save()) {
                    return new Par2CandidateRecoveryResult(Par2RenameOutcome::Deferred, $probes, $articles);
                }
                if (self::isBlocked($checkpoint)) {
                    return new Par2CandidateRecoveryResult(Par2RenameOutcome::Retryable, $probes, $articles);
                }
                $outcome = $apply();
                if ($outcome !== Par2RenameOutcome::Retryable) {
                    return new Par2CandidateRecoveryResult($outcome === Par2RenameOutcome::Deferred ? $outcome : $finish($outcome), $probes, $articles);
                }
            }
            $saved = $save('incomplete_stream');

            return new Par2CandidateRecoveryResult($saved ? Par2RenameOutcome::Retryable : Par2RenameOutcome::Deferred, $probes, $articles);
        } catch (Throwable) {
            return new Par2CandidateRecoveryResult(self::owns($guard) ? Par2RenameOutcome::Retryable : Par2RenameOutcome::Deferred, $probes, $articles);
        }
    }

    /**
     * @param  callable():bool  $guard
     *
     * @phpstan-impure
     */
    private static function owns(callable $guard): bool
    {
        return $guard();
    }

    private static function isBlocked(?Par2CandidateCheckpoint $checkpoint): bool
    {
        return $checkpoint?->blockedReason !== null;
    }

    /**
     * @param  array{id:int,guid:string,name:?string,searchname:?string}  $snapshot
     *
     * @phpstan-impure
     */
    private static function snapshotCurrent(array $snapshot, int $groupId): bool
    {
        $release = DB::table('releases')->where('id', $snapshot['id'])->first(['guid', 'name', 'searchname', 'groups_id', 'isrenamed', 'predb_id', 'proc_par2']);

        return $release !== null && $release->guid === $snapshot['guid'] && $release->name === $snapshot['name']
            && $release->searchname === $snapshot['searchname'] && (int) $release->groups_id === $groupId
            && (int) $release->isrenamed === 0 && (int) $release->predb_id === 0 && (int) $release->proc_par2 !== 1;
    }

    /**
     * @param  array{name_base64:string,size:int,part:?int}|null  $identity
     * @return array{name_base64:string,size:int,part:?int}|null
     */
    private function validatePart(?YencPart $part, string $body, int $segmentNumber, int $segmentCount, int $offset, ?array $identity): ?array
    {
        if ($part === null) {
            if ($identity !== null) {
                throw new InvalidArgumentException('yEnc framing changed between articles');
            }

            return null;
        }
        if ($part->name === '' || strlen($part->name) > 65536 || strpbrk($part->name, "\0\r\n") !== false || $part->size < 1
            || ($offset > 0 && $identity === null) || ($part->part !== null && $part->part !== $segmentNumber)) {
            throw new InvalidArgumentException('Invalid yEnc filename, size or part identity');
        }
        if ($identity !== null && (base64_encode($part->name) !== $identity['name_base64'] || $part->size !== $identity['size']
            || ($identity['part'] !== null && ($part->part === null || $part->part !== $identity['part'] + 1)))) {
            throw new InvalidArgumentException('yEnc file identity changed between articles');
        }
        if ($part->begin === null || $part->end === null) {
            if ($segmentCount !== 1 || $offset !== 0 || $part->begin !== null || $part->end !== null || strlen($body) !== $part->size) {
                throw new InvalidArgumentException('Missing yEnc multipart offsets');
            }
        } elseif ($offset === PHP_INT_MAX || $part->begin !== $offset + 1 || $part->end < $part->begin || $part->end > $part->size || $part->end - $part->begin + 1 !== strlen($body)) {
            throw new InvalidArgumentException('Discontinuous yEnc article offsets');
        }

        return ['name_base64' => base64_encode($part->name), 'size' => $part->size, 'part' => $part->part];
    }
}
