<?php

declare(strict_types=1);

namespace App\Services\Releases\Verification;

/**
 * What became of one remotely published candidate. The status names are the batch endpoint's
 * wire vocabulary; the primary and the worker share this type so neither invents its own.
 */
final class PublicationOutcome
{
    public const string PUBLISHED = 'published';

    /** Policy rejected the candidate; the row is terminal and holds no release. */
    public const string FILTERED = 'filtered';

    /** A covered release lacks the supplied metadata; the candidate is retained for review. */
    public const string METADATA_REVIEW = 'metadata_review';

    /** The claim no longer owns the candidate generation. */
    public const string STALE = 'stale';

    /** An exclusive maintenance holder or a durable pause forbids publication; retry later. */
    public const string PAUSED = 'paused';

    /** Not started (the batch ran out of time); nothing was read or written for it. */
    public const string RETRY = 'retry';

    public const string INVALID = 'invalid';

    public const string ERROR = 'error';

    /** Primary only: publication returned no release and the row names none, as the single endpoint reports it. */
    public const string UNRESOLVED = 'unresolved';

    /** Worker only: nothing was sent, or the single endpoint answered without a release; read the row for why. */
    public const string NO_RELEASE = 'no_release';

    public function __construct(public readonly string $status, public readonly ?int $releaseId = null, public readonly ?string $error = null) {}

    public function published(): bool
    {
        return $this->status === self::PUBLISHED && $this->releaseId !== null;
    }
}
