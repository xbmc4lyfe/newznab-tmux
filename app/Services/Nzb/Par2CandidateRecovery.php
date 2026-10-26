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
use App\Support\RustPipelineLibrary;
use dariusiii\rarinfo\Par2Info;
use FFI;
use FFI\CData;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LengthException;
use Throwable;

/** Reconstruct one ordered candidate without downloading committed articles again. */
final class Par2CandidateRecovery
{
    public const string FFI_CDEF = <<<'C'
        int32_t nntmux_par2_recovery_validate_part(uint32_t has_part, const char *name_ptr, size_t name_len, int64_t size, uint32_t has_number, int64_t number, uint32_t has_begin, int64_t begin, uint32_t has_end, int64_t end, size_t body_len, int64_t segment_number, int64_t segment_count, int64_t offset, uint32_t has_identity, const char *identity_b64_ptr, size_t identity_b64_len, int64_t identity_size, uint32_t identity_has_part, int64_t identity_part);
        C;

    private static FFI|false|null $ffi = null;

    private static bool $useRustFfi = true;

    public function __construct(private readonly NNTPService $nntp, private readonly PostProcessService $postprocess, private readonly bool $alternate = false) {}

    public static function setUseRustFfi(bool $use): void
    {
        self::$useRustFfi = $use;
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi;
    }

    private static function resolveFfi(): ?FFI
    {
        if (self::$ffi === null) {
            self::$ffi = RustPipelineLibrary::resolveDomain('additional', self::FFI_CDEF) ?? false;
        }

        return self::$ffi === false ? null : self::$ffi;
    }

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
        return self::validatePartPhp($part, $body, $segmentNumber, $segmentCount, $offset, $identity);
    }

    /**
     * Rust candidate for {@see validatePartPhp()}; `$supported` is false when the library is unavailable.
     *
     * @param  array{name_base64:string,size:int,part:?int}|null  $identity
     * @return array{name_base64:string,size:int,part:?int}|null
     */
    public static function validatePartNative(?YencPart $part, string $body, int $segmentNumber, int $segmentCount, int $offset, ?array $identity, bool &$supported = false): ?array
    {
        $supported = false;
        if (! self::$useRustFfi) {
            return null;
        }
        $ffi = self::$ffi ?? self::resolveFfi();
        if ($ffi === null || $ffi === false) {
            return null;
        }
        $name = $part === null ? '' : $part->name;
        $identityName = $identity === null ? '' : $identity['name_base64'];
        // @phpstan-ignore method.notFound
        $code = $ffi->nntmux_par2_recovery_validate_part(
            $part === null ? 0 : 1, $name, \strlen($name), $part === null ? 0 : $part->size,
            $part?->part === null ? 0 : 1, $part->part ?? 0,
            $part?->begin === null ? 0 : 1, $part->begin ?? 0,
            $part?->end === null ? 0 : 1, $part->end ?? 0,
            \strlen($body), $segmentNumber, $segmentCount, $offset,
            $identity === null ? 0 : 1, $identityName, \strlen($identityName), $identity === null ? 0 : $identity['size'],
            $identity === null || $identity['part'] === null ? 0 : 1, $identity['part'] ?? 0,
        );
        if (! \is_int($code) || $code < 0) {
            return null;
        }
        $supported = true;

        return match ($code) {
            0 => null,
            1 => ['name_base64' => base64_encode($name), 'size' => $part === null ? 0 : $part->size, 'part' => $part?->part],
            2 => throw new InvalidArgumentException('yEnc framing changed between articles'),
            3 => throw new InvalidArgumentException('Invalid yEnc filename, size or part identity'),
            4 => throw new InvalidArgumentException('yEnc file identity changed between articles'),
            5 => throw new InvalidArgumentException('Missing yEnc multipart offsets'),
            default => throw new InvalidArgumentException('Discontinuous yEnc article offsets'),
        };
    }

    /**
     * @param  array{name_base64:string,size:int,part:?int}|null  $identity
     * @return array{name_base64:string,size:int,part:?int}|null
     */
    public static function validatePartPhp(?YencPart $part, string $body, int $segmentNumber, int $segmentCount, int $offset, ?array $identity): ?array
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

    /**
     * Default for the Wave 42.2 toggle only; it stays false because the paired benchmark in
     * tests/Unit/RustNzbSourceGuardInstallStagingXmlPar2W42ParityTest.php is below 1.5x.
     */
    public const bool W422_RUST_FFI_DEFAULT = false;

    private static ?bool $useW422RustFfi = null;

    public static function useW422RustFfi(bool $enable = true): void
    {
        self::$useW422RustFfi = $enable;
    }

    public static function usesW422RustFfi(): bool
    {
        return self::$useW422RustFfi ?? self::W422_RUST_FFI_DEFAULT;
    }

    public static function resetW422RustFfi(): void
    {
        self::$useW422RustFfi = null;
    }

    /**
     * Walk yEnc part offsets (`begin`, `end`) from decoded offset zero, as validatePartPhp() checks multipart continuity:
     * invalid unless both are integers, out_of_range unless 1 <= begin <= end <= $size, overlap when begin is at or before
     * the offset, contiguous (advancing the offset to end) when begin is the next byte, otherwise gap. `missing_bytes` is
     * max(0, $size) minus the final offset.
     *
     * @param  array<array-key, mixed>  $parts
     * @return array{parts: int, invalid: int, contiguous: int, gaps: int, overlaps: int, out_of_range: int, decoded_offset: int, missing_bytes: int}
     */
    public static function summarizePartOffsets(array $parts, int $size): array
    {
        return (self::usesW422RustFfi() ? self::summarizePartOffsetsNative($parts, $size) : null)
            ?? self::summarizePartOffsetsPhp($parts, $size);
    }

    /**
     * @param  array<array-key, mixed>  $parts
     * @return array{parts: int, invalid: int, contiguous: int, gaps: int, overlaps: int, out_of_range: int, decoded_offset: int, missing_bytes: int}
     */
    public static function summarizePartOffsetsPhp(array $parts, int $size): array
    {
        $invalid = $contiguous = $gaps = $overlaps = $outOfRange = $offset = 0;
        foreach ($parts as $part) {
            [$valid, $begin, $end] = self::partOffsetFields($part);
            if ($valid === 0) {
                $invalid++;
            } elseif ($begin < 1 || $end < $begin || $end > $size) {
                $outOfRange++;
            } elseif ($begin <= $offset) {
                $overlaps++;
            } elseif ($begin === $offset + 1) {
                $contiguous++;
                $offset = $end;
            } else {
                $gaps++;
            }
        }

        return ['parts' => \count($parts), 'invalid' => $invalid, 'contiguous' => $contiguous, 'gaps' => $gaps, 'overlaps' => $overlaps,
            'out_of_range' => $outOfRange, 'decoded_offset' => $offset, 'missing_bytes' => max(0, $size) - $offset];
    }

    /**
     * @param  array<array-key, mixed>  $parts
     * @return array{parts: int, invalid: int, contiguous: int, gaps: int, overlaps: int, out_of_range: int, decoded_offset: int, missing_bytes: int}|null
     */
    public static function summarizePartOffsetsNative(array $parts, int $size): ?array
    {
        if (\count($parts) * 3 + 1 > NzbSourceGuard::W422_MAX_INPUT / 8) {
            return null;
        }
        $words = [$size];
        foreach ($parts as $part) {
            array_push($words, ...self::partOffsetFields($part));
        }
        $slots = NzbSourceGuard::evaluateW422Native(4, pack('q*', ...$words), 8);
        if ($slots === null) {
            return null;
        }
        [$count, $invalid, $contiguous, $gaps, $overlaps, $outOfRange, $offset, $missing] = $slots;
        if ($count !== \count($parts) || min($slots) < 0 || $invalid + $contiguous + $gaps + $overlaps + $outOfRange !== $count
            || $offset > max(0, $size) || $missing !== max(0, $size) - $offset) {
            return null;
        }

        return ['parts' => $count, 'invalid' => $invalid, 'contiguous' => $contiguous, 'gaps' => $gaps, 'overlaps' => $overlaps,
            'out_of_range' => $outOfRange, 'decoded_offset' => $offset, 'missing_bytes' => $missing];
    }

    /** @return array{0|1, int, int} whether begin and end are both integers, then begin and end */
    private static function partOffsetFields(mixed $part): array
    {
        if (! \is_array($part)) {
            return [0, 0, 0];
        }
        $begin = $part['begin'] ?? null;
        $end = $part['end'] ?? null;

        return \is_int($begin) && \is_int($end) ? [1, $begin, $end] : [0, 0, 0];
    }

    // ---- Wave 60.2 batch plan ----------------------------------------------------------------

    /** Enabled: the minimum of three paired runs cleared every 1.5x gate (common mean/p99 6.26x/5.63x, uncommon 6.44x/6.59x); see RustNzbUploadStagingXmlElementsPar2RecoveryResultFingerprintStoreW60ParityTest. */
    public const bool W60_RUST_FFI_DEFAULT = true;

    private const string W60_CANDIDATE_RECOVERY_FFI_CDEF = <<<'C'
        intptr_t nntmux_w602_par2_candidate_recovery_plan_batch(const char *items, size_t items_len, size_t count, int64_t *out, size_t out_cap);
        C;

    /** Summary words after the per-row words, the digest included. */
    public const int W60_CANDIDATE_RECOVERY_TAIL = 10;

    /**
     * Wave 60 routing, binding and output buffer, kept in function-local statics (no new static properties).
     * Operations: null reads the routing override, 'set' replaces it (returning the previous
     * one), 'ffi' resolves the binding and 'out' returns `[binding, buffer, capacity]` with room
     * for at least $val words (null without the library).
     */
    private static function w60RustFfiState(?string $op = null, mixed $val = null): mixed
    {
        static $enabled = null;
        static $ffi = null;
        static $out = null;
        static $capacity = 0;

        switch ($op) {
            case 'set':
                $previous = $enabled;
                $enabled = \is_bool($val) ? $val : null;

                return $previous;
            case 'ffi':
                $ffi ??= RustPipelineLibrary::resolveDomain('nfo_w60', self::W60_CANDIDATE_RECOVERY_FFI_CDEF) ?? false;

                return $ffi === false ? null : $ffi;
            case 'out':
                $words = \is_int($val) ? $val : 0;
                if (! $out instanceof CData || $capacity < $words) {
                    $resolved = self::w60RustFfiState('ffi');
                    $buffer = $resolved instanceof FFI ? $resolved->new('int64_t['.max(1024, $words).']') : null;
                    if (! $buffer instanceof CData) {
                        return null;
                    }
                    [$out, $capacity] = [$buffer, max(1024, $words)];
                }

                return [$ffi, $out, $capacity];
            default:
                return $enabled;
        }
    }

    public static function usesW60RustFfi(): bool
    {
        $enabled = self::w60RustFfiState();

        return \is_bool($enabled) ? $enabled : self::W60_RUST_FFI_DEFAULT;
    }

    /**
     * Runs $callback with planCandidateRecoveryBatch() routed to Rust ($enabled) or PHP, then restores the
     * previous routing.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW60RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w60RustFfiState('set', $enabled);
        try {
            return $callback();
        } finally {
            self::w60RustFfiState('set', $previous);
        }
    }

    /** @param  list<int>  $out */
    private static function w60Digest(array $out): int
    {
        /** @var array{1: int} $digest */
        $digest = unpack('J', hash('xxh64', pack('P*', ...$out), true));

        return $digest[1];
    }

    /**
     * The words the export wrote, or null when it declined (returning anything but $words).
     *
     * @return list<int>|null
     */
    private static function w60Words(mixed $written, CData $out, int $words): ?array
    {
        /** @var array<int, int>|false $values */
        $values = $written === $words ? unpack('P*', FFI::string($out, $words * 8)) : false;

        return \is_array($values) ? array_values($values) : null;
    }

    /** 1 to $max ASCII digits as an integer, otherwise null. */
    private static function w60Digits(string $value, int $max): ?int
    {
        $length = \strlen($value);

        return $length > 0 && $length <= $max && strspn($value, '0123456789') === $length ? (int) $value : null;
    }

    /** PAR2 packet type => coverage bit. */
    private const array W60_PACKETS = ['main' => 1, 'filedesc' => 2, 'ifsc' => 4, 'recvslice' => 8, 'creator' => 16];

    /** Status => base recovery priority. */
    private const array W60_STATUS_BASE = [0, 100, 80, 10, 30];

    /**
     * Per `set_id\tfiles\tpackets\tneeded\tavailable\tname` row `status | coverage << 3 | null_set << 8 |
     * volume << 9 | par2 << 10 | min(unknown, 15) << 11 | score << 15 | min(files, 0xFFFF) << 23 |
     * (crc32(lower(set_id)) & 0xFFFFFF) << 39`. Status: 0 invalid (a set id that is not 32 hex
     * digits or is all zero, files not 1-32768 or block counts not 1-9 digits), 4 incomplete (no
     * main or file description packet), 1 complete (no blocks needed), 2 repairable (needed <=
     * available and a recovery slice packet), 3 insufficient. Coverage is the bitmask of the
     * comma-separated packet types (main, filedesc, ifsc, recvslice, creator; any case, trimmed of
     * spaces and tabs, empty tokens skipped), unknown the count of other tokens. Volume: the name
     * holds `.vol`; par2: it ends in `.par2` (both any case). Score: 0 when invalid, otherwise the
     * status base plus 5 per covered type, minus 2 per unknown token (at most 10), plus 7 for an
     * index (non-volume) and 3 for a `.par2` name, within 0-255. Then the per-status counts, the
     * distinct set ids, needed and available block totals of the valid rows, the highest score and
     * the XXH64 digest of all earlier words.
     *
     * @param  array<array-key, string>  $rows
     * @return list<int>
     */
    public static function planCandidateRecoveryBatchPhp(array $rows): array
    {
        $out = $seen = [];
        $statuses = [0, 0, 0, 0, 0];
        $neededTotal = $availableTotal = $maxScore = 0;
        foreach ($rows as $row) {
            [$set, $files, $packets, $needed, $available, $name] = explode("\t", (string) $row, 6) + ['', '', '', '', '', ''];
            $setLower = strtolower($set);
            $setValid = \strlen($set) === 32 && strspn($setLower, '0123456789abcdef') === 32;
            $nullSet = $setValid && $set === '00000000000000000000000000000000';
            $fileCount = self::w60Digits($files, 6);
            $need = self::w60Digits($needed, 9);
            $have = self::w60Digits($available, 9);
            $coverage = $unknown = 0;
            foreach (explode(',', $packets) as $token) {
                $token = strtolower(trim($token, " \t"));
                if ($token === '') {
                    continue;
                }
                if (isset(self::W60_PACKETS[$token])) {
                    $coverage |= self::W60_PACKETS[$token];
                } else {
                    $unknown++;
                }
            }
            $lowerName = strtolower($name);
            $par2 = str_ends_with($lowerName, '.par2');
            $volume = str_contains($lowerName, '.vol');
            if (! $setValid || $nullSet || $fileCount === null || $fileCount < 1 || $fileCount > 32768 || $need === null || $have === null) {
                $status = 0;
            } elseif (($coverage & 3) !== 3) {
                $status = 4;
            } elseif ($need === 0) {
                $status = 1;
            } elseif ($need <= $have && ($coverage & 8) !== 0) {
                $status = 2;
            } else {
                $status = 3;
            }
            $score = $status === 0 ? 0 : max(0, min(255, self::W60_STATUS_BASE[$status] + substr_count(decbin($coverage), '1') * 5
                - min($unknown, 10) * 2 + ($volume ? 0 : 7) + ($par2 ? 3 : 0)));
            $statuses[$status]++;
            if ($status !== 0) {
                $seen[$setLower] = true;
                $neededTotal += (int) $need;
                $availableTotal += (int) $have;
                $maxScore = max($maxScore, $score);
            }
            $out[] = $status | $coverage << 3 | (int) $nullSet << 8 | (int) $volume << 9 | (int) $par2 << 10 | min($unknown, 15) << 11 | $score << 15
                | min($fileCount ?? 0, 0xFFFF) << 23 | (crc32($setLower) & 0xFFFFFF) << 39;
        }
        array_push($out, ...$statuses);
        array_push($out, \count($seen), $neededTotal, $availableTotal, $maxScore);
        $out[] = self::w60Digest($out);

        return $out;
    }

    /**
     * @param  array<array-key, string>  $rows
     * @return list<int>
     */
    public static function planCandidateRecoveryBatch(array $rows): array
    {
        return self::usesW60RustFfi() ? self::planCandidateRecoveryBatchNative($rows) : self::planCandidateRecoveryBatchPhp($rows);
    }

    /**
     * Rust batch; falls back to PHP when the library is missing, a row holds a NUL byte or the
     * column is not a list.
     *
     * @param  array<array-key, string>  $rows
     * @return list<int>
     */
    public static function planCandidateRecoveryBatchNative(array $rows): array
    {
        $count = \count($rows);
        $words = $count + self::W60_CANDIDATE_RECOVERY_TAIL;
        /** @var array{FFI, CData, int}|null $state */
        $state = $count === 0 ? null : self::w60RustFfiState('out', $words);
        if ($state === null || ! array_is_list($rows)) {
            return self::planCandidateRecoveryBatchPhp($rows);
        }
        $blob = implode("\0", $rows);
        [$ffi, $out, $cap] = $state;
        try {
            // @phpstan-ignore method.notFound (Declared by W60_CANDIDATE_RECOVERY_FFI_CDEF.)
            $written = $ffi->nntmux_w602_par2_candidate_recovery_plan_batch($blob, \strlen($blob), $count, $out, $cap);
        } catch (Throwable) {
            $written = -1;
        }

        return self::w60Words($written, $out, $words) ?? self::planCandidateRecoveryBatchPhp($rows);
    }
}
