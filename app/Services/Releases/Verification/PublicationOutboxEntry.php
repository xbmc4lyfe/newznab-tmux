<?php

declare(strict_types=1);

namespace App\Services\Releases\Verification;

/**
 * One verified candidate waiting in the per-host publication outbox. It holds exactly what the
 * primary needs: the claim (fenced by its lease token), the reason and the sampled evidence.
 */
final readonly class PublicationOutboxEntry
{
    /**
     * @param  array{id: int, version: int, manifest_hash: string, lease_token: string}  $claim
     * @param  array{articles: int, bytes: int, sampled_files: int, verified_file_names: list<string>, identity_hints: list<string>}  $evidence
     * @param  float  $enqueuedAt  Unix seconds when the worker handed the result off.
     * @param  int  $attempts  Failed sends so far that count toward the attempt limit.
     * @param  int  $deferrals  Sends that never reached the primary's processing (unreachable, or not started); they only back off.
     * @param  float  $notBefore  Unix seconds before which a requeued entry is not sent again.
     * @param  int  $modifiedAt  Unix seconds of the file's last modification.
     */
    public function __construct(public string $name, public string $path, public array $claim, public string $reason, public array $evidence,
        public float $enqueuedAt, public int $attempts = 0, public float $notBefore = 0.0, public int $modifiedAt = 0, public int $deferrals = 0) {}

    /**
     * The name of an entry's file: one per lease of a candidate generation. A candidate that is claimed
     * again keeps its id and version, so the lease token is part of the name; an operation on an old
     * entry can then never touch the new one.
     */
    public static function nameFor(int $id, int $version, string $leaseToken): string
    {
        return $id.'-'.$version.'-'.substr(sha1($leaseToken), 0, 12).'.json';
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['claim' => $this->claim, 'reason' => $this->reason, 'evidence' => $this->evidence,
            'enqueued_at' => $this->enqueuedAt, 'attempts' => $this->attempts, 'deferrals' => $this->deferrals, 'not_before' => $this->notBefore];
    }

    public function claim(): object
    {
        return (object) $this->claim;
    }

    /** The item {@see RemoteReleasePublisher::publishMany()} sends; its manifest carries exactly this entry's evidence. */
    public function item(): RemotePublishItem
    {
        $manifest = ['verification_mode' => 'spot_check',
            'spot_check' => ['articles' => $this->evidence['articles'], 'bytes' => $this->evidence['bytes'], 'sampled_files' => $this->evidence['sampled_files']],
            'verified_file_names' => $this->evidence['verified_file_names'],
            'identity_hints' => array_map(static fn (string $name): array => ['name' => $name], $this->evidence['identity_hints'])];

        return new RemotePublishItem($this->claim(), new VerificationResult('sampled', $this->reason, $manifest));
    }

    /** Whether it has waited longer than $seconds since the worker handed it off. */
    public function olderThan(float $seconds, float $now): bool
    {
        return $now - $this->enqueuedAt > $seconds;
    }

    /**
     * The same entry after one more failed send, not to be sent again before $notBefore. A send the
     * primary never processed ($counted false) does not use up an attempt; only the age limit ends those.
     */
    public function withFailure(float $notBefore, bool $counted): self
    {
        return new self($this->name, $this->path, $this->claim, $this->reason, $this->evidence, $this->enqueuedAt,
            $this->attempts + ($counted ? 1 : 0), $notBefore, $this->modifiedAt, $this->deferrals + ($counted ? 0 : 1));
    }

    /** Failed sends of either kind, for the backoff. */
    public function failures(): int
    {
        return $this->attempts + $this->deferrals;
    }

    /** The decoded entry, or null when the document is not a valid one. */
    public static function decode(string $name, string $path, string $json, int $modifiedAt): ?self
    {
        $data = json_decode($json, true);
        if (! is_array($data) || ! is_array($data['claim'] ?? null) || ! is_array($data['evidence'] ?? null)) {
            return null;
        }
        $claim = $data['claim'];
        $evidence = $data['evidence'];
        if (! is_int($claim['id'] ?? null) || ! is_int($claim['version'] ?? null) || ! is_string($claim['manifest_hash'] ?? null)
            || ! is_string($claim['lease_token'] ?? null) || ! is_string($data['reason'] ?? null)
            || ! is_int($evidence['articles'] ?? null) || ! is_int($evidence['bytes'] ?? null) || ! is_int($evidence['sampled_files'] ?? null)
            || ! is_array($evidence['verified_file_names'] ?? null) || ! is_array($evidence['identity_hints'] ?? null)) {
            return null;
        }

        return new self($name, $path, ['id' => $claim['id'], 'version' => $claim['version'], 'manifest_hash' => $claim['manifest_hash'],
            'lease_token' => $claim['lease_token']], $data['reason'],
            ['articles' => $evidence['articles'], 'bytes' => $evidence['bytes'], 'sampled_files' => $evidence['sampled_files'],
                'verified_file_names' => array_values(array_map('strval', $evidence['verified_file_names'])),
                'identity_hints' => array_values(array_map('strval', $evidence['identity_hints']))],
            (float) ($data['enqueued_at'] ?? 0), max(0, (int) ($data['attempts'] ?? 0)), (float) ($data['not_before'] ?? 0), $modifiedAt, max(0, (int) ($data['deferrals'] ?? 0)));
    }
}
