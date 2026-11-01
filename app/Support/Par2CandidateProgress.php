<?php

declare(strict_types=1);

namespace App\Support;

use App\Services\Par2PacketAccumulator;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;
use stdClass;
use Throwable;
use UnexpectedValueException;

/**
 * Durable article cursors and metadata. Manifest signatures must cover every ordered candidate's
 * segment number, Message-ID and byte estimate, plus configuration limits that can block recovery.
 * A blocked checkpoint is never evidence of completeness; only a new manifest or explicit reset
 * clears its block. A null accumulator is permitted only for a zero-byte blocked tombstone.
 */
final class Par2CandidateProgress
{
    private const MAX_SEGMENTS = 1000000;

    public static function load(int $releaseId, string $manifestSignature, string $candidateSignature, int $segmentCount, int $maxStateBytes): ?Par2CandidateCheckpoint
    {
        self::validateArguments($releaseId, $manifestSignature, $candidateSignature, $segmentCount, $maxStateBytes);

        return DB::transaction(function () use ($releaseId, $manifestSignature, $candidateSignature, $segmentCount, $maxStateBytes): ?Par2CandidateCheckpoint {
            DB::table('releases')->where('id', $releaseId)->lockForUpdate()->first(['id']);
            $parent = DB::table('par2_rename_progress')->where('releases_id', $releaseId)->first();
            if ($parent === null || $parent->signature !== $manifestSignature) {
                return null;
            }
            $found = null;
            $total = 0;
            foreach (DB::table('par2_rename_candidate_progress')->where('releases_id', $releaseId)->get() as $row) {
                $checkpoint = self::decodeRow($row, $row->candidate_signature === $candidateSignature ? $segmentCount : self::MAX_SEGMENTS);
                if ($checkpoint->storedBytes > $maxStateBytes - $total) {
                    throw new UnexpectedValueException('The release checkpoint state exceeds its aggregate byte ceiling.');
                }
                $total += $checkpoint->storedBytes;
                if ($row->candidate_signature === $candidateSignature) {
                    $found = $checkpoint;
                }
            }

            return $found;
        });
    }

    /**
     * A true return means the checkpoint (possibly a state_limit tombstone) is durable; reload it
     * before continuing. False means the release snapshot or ownership claim no longer matches.
     *
     * @param  array{id: int, guid: string, name: ?string, searchname: ?string}  $snapshot
     * @param  list<int>  $retrySegments
     * @param  array<string, mixed>|null  $accumulatorState
     * @param  callable(): bool  $guard
     * @param  array{name_base64: string, size: int, part: ?int}|null  $articleIdentity
     */
    public static function save(array $snapshot, string $manifestSignature, string $candidateSignature, int $nextSegment, array $retrySegments, ?array $accumulatorState, int $segmentCount, int $maxStateBytes, callable $guard, ?string $blockedReason = null, int $decodedOffset = 0, ?array $articleIdentity = null): bool
    {
        self::validateArguments($snapshot['id'], $manifestSignature, $candidateSignature, $segmentCount, $maxStateBytes);
        self::validateCursor($nextSegment, $retrySegments, $segmentCount);
        self::validateBlock($blockedReason);
        [$state, $bytes] = self::canonicalState($accumulatorState, $blockedReason);
        $articleIdentity = self::canonicalIdentity($articleIdentity, $decodedOffset, $state === null);
        $bytes += $articleIdentity === null ? 0 : strlen(json_encode($articleIdentity, JSON_THROW_ON_ERROR));
        $aborted = new RuntimeException('PAR2 checkpoint ownership or release identity changed.');

        try {
            return DB::transaction(function () use ($snapshot, $manifestSignature, $candidateSignature, $nextSegment, $retrySegments, $state, $bytes, $segmentCount, $maxStateBytes, $guard, $blockedReason, $decodedOffset, $articleIdentity, $aborted): bool {
                self::assertWritableSnapshot($snapshot, $guard, $aborted);
                $parent = DB::table('par2_rename_progress')->where('releases_id', $snapshot['id'])->first();
                if ($parent !== null && $parent->signature !== $manifestSignature) {
                    DB::table('par2_rename_progress')->where('releases_id', $snapshot['id'])->delete();
                    $parent = null;
                }
                if ($parent === null) {
                    DB::table('par2_rename_progress')->insert([
                        'releases_id' => $snapshot['id'], 'signature' => $manifestSignature,
                        'offset' => 0, 'retry_indexes' => '[]', 'updated_at' => now(),
                    ]);
                }

                $otherBytes = 0;
                $existingBlock = false;
                $existingBytes = 0;
                foreach (DB::table('par2_rename_candidate_progress')->where('releases_id', $snapshot['id'])->get() as $row) {
                    $checkpoint = self::decodeRow($row, $row->candidate_signature === $candidateSignature ? $segmentCount : self::MAX_SEGMENTS);
                    if ($row->candidate_signature === $candidateSignature) {
                        $existingBlock = $checkpoint->blockedReason !== null;
                        $existingBytes = $checkpoint->storedBytes;
                    } else {
                        if ($checkpoint->storedBytes > $maxStateBytes - $otherBytes) {
                            throw new UnexpectedValueException('Existing release checkpoint state exceeds its byte ceiling.');
                        }
                        $otherBytes += $checkpoint->storedBytes;
                    }
                }
                if ($existingBlock && $existingBytes > $maxStateBytes - $otherBytes) {
                    throw new UnexpectedValueException('Existing blocked checkpoint exceeds the release byte ceiling.');
                }
                if (! $existingBlock) {
                    if ($bytes > $maxStateBytes - $otherBytes) {
                        $state = null;
                        $bytes = 0;
                        $blockedReason = 'state_limit';
                        $articleIdentity = null;
                    }
                    self::assertWritableSnapshot($snapshot, $guard, $aborted);
                    DB::table('par2_rename_candidate_progress')->updateOrInsert([
                        'releases_id' => $snapshot['id'], 'candidate_signature' => $candidateSignature,
                    ], [
                        'next_segment' => $nextSegment,
                        'retry_segments' => json_encode($retrySegments, JSON_THROW_ON_ERROR),
                        'accumulator_state' => $state === null ? null : json_encode($state, JSON_THROW_ON_ERROR),
                        'decoded_offset' => $decodedOffset,
                        'article_identity' => $articleIdentity === null ? null : json_encode($articleIdentity, JSON_THROW_ON_ERROR),
                        'stored_bytes' => $bytes, 'blocked_reason' => $blockedReason, 'updated_at' => now(),
                    ]);
                }
                self::assertWritableSnapshot($snapshot, $guard, $aborted);

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
     * Quarantine an existing malformed candidate without trusting its cursor or byte accounting.
     * Valid or absent candidates return false. Sibling corruption or excess remains an explicit
     * error. A tombstone cannot establish completeness and remains blocked until manifest reset.
     *
     * @param  array{id: int, guid: string, name: ?string, searchname: ?string}  $snapshot
     * @param  callable(): bool  $guard
     */
    public static function blockCorruptCandidate(array $snapshot, string $manifestSignature, string $candidateSignature, int $segmentCount, int $maxStateBytes, callable $guard): bool
    {
        self::validateArguments($snapshot['id'], $manifestSignature, $candidateSignature, $segmentCount, $maxStateBytes);
        $aborted = new RuntimeException('PAR2 checkpoint ownership, release identity or manifest changed.');
        try {
            return DB::transaction(function () use ($snapshot, $manifestSignature, $candidateSignature, $segmentCount, $maxStateBytes, $guard, $aborted): bool {
                self::assertWritableSnapshot($snapshot, $guard, $aborted);
                $parent = DB::table('par2_rename_progress')->where('releases_id', $snapshot['id'])->lockForUpdate()->first();
                if ($parent === null || $parent->signature !== $manifestSignature) {
                    throw $aborted;
                }
                $corruptTarget = false;
                $otherBytes = 0;
                foreach (DB::table('par2_rename_candidate_progress')->where('releases_id', $snapshot['id'])->lockForUpdate()->get() as $row) {
                    if ($row->candidate_signature === $candidateSignature) {
                        try {
                            self::decodeRow($row, $segmentCount);
                        } catch (UnexpectedValueException) {
                            $corruptTarget = true;
                        }
                    } else {
                        $checkpoint = self::decodeRow($row, self::MAX_SEGMENTS);
                        if ($checkpoint->storedBytes > $maxStateBytes - $otherBytes) {
                            throw new UnexpectedValueException('Existing release checkpoint state exceeds its byte ceiling.');
                        }
                        $otherBytes += $checkpoint->storedBytes;
                    }
                }
                if (! $corruptTarget) {
                    return false;
                }
                self::assertWritableSnapshot($snapshot, $guard, $aborted);
                DB::table('par2_rename_candidate_progress')->where('releases_id', $snapshot['id'])->where('candidate_signature', $candidateSignature)->update([
                    'next_segment' => 0, 'retry_segments' => '[]', 'accumulator_state' => null,
                    'decoded_offset' => 0, 'article_identity' => null, 'stored_bytes' => 0,
                    'blocked_reason' => 'malformed_state', 'updated_at' => now(),
                ]);
                self::assertWritableSnapshot($snapshot, $guard, $aborted);
                if (DB::table('par2_rename_progress')->where('releases_id', $snapshot['id'])->value('signature') !== $manifestSignature) {
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
     * @param  array{id: int, guid: string, name: ?string, searchname: ?string}  $snapshot
     * @param  callable(): bool  $guard
     */
    public static function deleteCandidate(array $snapshot, string $manifestSignature, string $candidateSignature, callable $guard): bool
    {
        self::validateArguments($snapshot['id'], $manifestSignature, $candidateSignature, 1, 1);
        $aborted = new RuntimeException('PAR2 checkpoint ownership or release identity changed.');
        try {
            return DB::transaction(function () use ($snapshot, $manifestSignature, $candidateSignature, $guard, $aborted): bool {
                self::assertWritableSnapshot($snapshot, $guard, $aborted);
                $parent = DB::table('par2_rename_progress')->where('releases_id', $snapshot['id'])->first();
                if ($parent === null || $parent->signature !== $manifestSignature) {
                    throw $aborted;
                }
                DB::table('par2_rename_candidate_progress')->where('releases_id', $snapshot['id'])->where('candidate_signature', $candidateSignature)->delete();
                self::assertWritableSnapshot($snapshot, $guard, $aborted);

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
     * @param  array{id: int, guid: string, name: ?string, searchname: ?string}  $snapshot
     * @param  callable(): bool  $guard
     *
     * @phpstan-impure
     */
    private static function assertWritableSnapshot(array $snapshot, callable $guard, RuntimeException $aborted): void
    {
        if (! $guard() || ! self::matchesSnapshot($snapshot)) {
            throw $aborted;
        }
    }

    /**
     * @param  array{id: int, guid: string, name: ?string, searchname: ?string}  $snapshot
     *
     * @phpstan-impure
     */
    private static function matchesSnapshot(array $snapshot): bool
    {
        $release = DB::table('releases')->where('id', $snapshot['id'])->lockForUpdate()
            ->first(['id', 'guid', 'name', 'searchname', 'predb_id', 'isrenamed', 'proc_par2']);

        return $release !== null && (int) $release->id === $snapshot['id']
            && $release->guid === $snapshot['guid'] && $release->name === $snapshot['name']
            && $release->searchname === $snapshot['searchname']
            && (int) $release->predb_id === 0 && (int) $release->isrenamed === 0 && (int) $release->proc_par2 !== 1;
    }

    private static function validateArguments(int $releaseId, string $manifestSignature, string $candidateSignature, int $segmentCount, int $maxStateBytes): void
    {
        if ($releaseId < 1 || $segmentCount < 1 || $segmentCount > self::MAX_SEGMENTS || $maxStateBytes < 1 || $maxStateBytes > 4294967295
            || ! preg_match('/\A[a-f0-9]{64}\z/', $manifestSignature) || ! preg_match('/\A[a-f0-9]{64}\z/', $candidateSignature)) {
            throw new InvalidArgumentException('Invalid PAR2 checkpoint identity, segment count or byte ceiling.');
        }
    }

    /** @param array<mixed> $retrySegments */
    private static function validateCursor(int $nextSegment, array $retrySegments, int $segmentCount): void
    {
        if ($nextSegment < 0 || $nextSegment > $segmentCount || ! array_is_list($retrySegments) || count($retrySegments) > $nextSegment) {
            throw new InvalidArgumentException('Invalid PAR2 article cursor or retry list.');
        }
        $seen = [];
        foreach ($retrySegments as $retry) {
            if (! is_int($retry) || $retry < 0 || $retry >= $nextSegment || isset($seen[$retry])) {
                throw new InvalidArgumentException('Invalid or duplicate PAR2 retry segment.');
            }
            $seen[$retry] = true;
        }
    }

    private static function validateBlock(?string $blockedReason): void
    {
        if ($blockedReason !== null && ! preg_match('/\A[a-z][a-z0-9_]{0,63}\z/', $blockedReason)) {
            throw new InvalidArgumentException('Invalid PAR2 checkpoint blocked reason.');
        }
    }

    /**
     * @param  array<string, mixed>|null  $state
     * @return array{array{version: 1, packets: list<string>, tail: string, skip_remaining: int}|null, int}
     */
    private static function canonicalState(?array $state, ?string $blockedReason): array
    {
        if ($state === null) {
            if ($blockedReason === null) {
                throw new InvalidArgumentException('A checkpoint without accumulator state must be blocked.');
            }

            return [null, 0];
        }
        $accumulator = Par2PacketAccumulator::fromState($state, PHP_INT_MAX);

        return [$accumulator->exportState(), $accumulator->stateBytes()];
    }

    /**
     * @param  array<string, mixed>|null  $identity
     * @return array{name_base64: string, size: int, part: ?int}|null
     */
    private static function canonicalIdentity(?array $identity, int $decodedOffset, bool $tombstone): ?array
    {
        if ($decodedOffset < 0) {
            throw new InvalidArgumentException('Invalid decoded article offset.');
        }
        if ($identity === null) {
            return null;
        }
        $keys = array_keys($identity);
        sort($keys);
        if ($tombstone || $keys !== ['name_base64', 'part', 'size']
            || ! is_string($identity['name_base64']) || strlen($identity['name_base64']) > 87384
            || ! is_int($identity['size']) || $identity['size'] < 1 || $decodedOffset > $identity['size']
            || ($identity['part'] !== null && (! is_int($identity['part']) || $identity['part'] < 1 || $identity['part'] > self::MAX_SEGMENTS))) {
            throw new InvalidArgumentException('Invalid article continuity identity.');
        }
        $name = base64_decode($identity['name_base64'], true);
        if ($name === false || $name === '' || strlen($name) > 65536 || base64_encode($name) !== $identity['name_base64'] || strpbrk($name, "\r\n\0") !== false) {
            throw new InvalidArgumentException('Invalid article filename encoding.');
        }

        return ['name_base64' => $identity['name_base64'], 'size' => $identity['size'], 'part' => $identity['part']];
    }

    private static function decodeRow(stdClass $row, int $segmentCount): Par2CandidateCheckpoint
    {
        try {
            if (! is_string($row->candidate_signature) || ! preg_match('/\A[a-f0-9]{64}\z/', $row->candidate_signature)) {
                throw new InvalidArgumentException('Invalid candidate signature.');
            }
            $nextSegment = self::storedInteger($row->next_segment, self::MAX_SEGMENTS);
            $storedBytes = self::storedInteger($row->stored_bytes, 4294967295);
            $retries = json_decode($row->retry_segments, flags: JSON_THROW_ON_ERROR);
            if (! is_array($retries)) {
                throw new InvalidArgumentException('Invalid stored retry list.');
            }
            self::validateCursor($nextSegment, $retries, $segmentCount);
            self::validateBlock($row->blocked_reason);
            if ($row->accumulator_state !== null) {
                $document = json_decode($row->accumulator_state, flags: JSON_THROW_ON_ERROR);
                if (! $document instanceof stdClass || ! isset($document->packets) || ! is_array($document->packets)) {
                    throw new InvalidArgumentException('Invalid stored accumulator object or packet list.');
                }
            }
            $decoded = $row->accumulator_state === null ? null : json_decode($row->accumulator_state, true, flags: JSON_THROW_ON_ERROR);
            if ($row->accumulator_state !== null && ! is_array($decoded)) {
                throw new InvalidArgumentException('Invalid stored accumulator state.');
            }
            [$state, $bytes] = self::canonicalState($decoded, $row->blocked_reason);
            $decodedOffset = self::storedInteger($row->decoded_offset, PHP_INT_MAX);
            $identity = $row->article_identity === null ? null : json_decode($row->article_identity, true, flags: JSON_THROW_ON_ERROR);
            if ($row->article_identity !== null && ! is_array($identity)) {
                throw new InvalidArgumentException('Invalid stored article identity.');
            }
            $identity = self::canonicalIdentity($identity, $decodedOffset, $state === null);
            $bytes += $identity === null ? 0 : strlen(json_encode($identity, JSON_THROW_ON_ERROR));
            if ($bytes !== $storedBytes) {
                throw new InvalidArgumentException('PAR2 checkpoint byte accounting does not match canonical state.');
            }

            return new Par2CandidateCheckpoint($nextSegment, $retries, $state, $bytes, $row->blocked_reason, $decodedOffset, $identity);
        } catch (Throwable $exception) {
            throw new UnexpectedValueException('Invalid persisted PAR2 candidate checkpoint.', previous: $exception);
        }
    }

    private static function storedInteger(mixed $value, int $maximum): int
    {
        if (is_int($value)) {
            if ($value < 0 || $value > $maximum) {
                throw new InvalidArgumentException('Invalid stored PAR2 checkpoint integer.');
            }

            return $value;
        }
        $limit = (string) $maximum;
        if (! is_string($value) || ! preg_match('/\A(?:0|[1-9][0-9]*)\z/', $value)
            || strlen($value) > strlen($limit)
            || (strlen($value) === strlen($limit) && strcmp($value, $limit) > 0)) {
            throw new InvalidArgumentException('Invalid stored PAR2 checkpoint integer.');
        }

        return (int) $value;
    }
}
