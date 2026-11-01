<?php

declare(strict_types=1);

namespace App\Services\NameFixing\Data;

use App\Support\PredbSearchDocument;
use InvalidArgumentException;

/** Observed nominations only; guarded writes must freshly establish SQL authority. */
final readonly class FilenamePass
{
    public const PREDB_IDENTITY_POLICY = '7e2393efa8f0ba6a813f8c43b3ff773909ca4cb4218db8311e3f07a78df4715b';

    /**
     * Ordered nomination observations, not a claim to the full persisted inventory.
     *
     * @param  list<string>  $filenames
     * @param  list<array{filename: string, match: array<string, mixed>|null, evidence: bool, complete: bool}>  $lookups
     */
    public function __construct(public array $filenames, public array $lookups, public ?string $localRejectionPolicy = null)
    {
        if ($localRejectionPolicy !== null && preg_match('/\A[0-9a-f]{64}\z/D', $localRejectionPolicy) !== 1) {
            throw new InvalidArgumentException('Invalid local rejection policy.');
        }
        if (! array_is_list($filenames) || ! array_is_list($lookups) || count($filenames) !== count($lookups)) {
            throw new InvalidArgumentException('Filename observations and lookups must be aligned lists.');
        }
        foreach ($filenames as $index => $filename) {
            $lookup = $lookups[$index];
            if (! is_string($filename) || ! is_array($lookup)
                || ($lookup['filename'] ?? null) !== $filename
                || ! array_key_exists('match', $lookup)
                || ! is_bool($lookup['complete'] ?? null) || ! is_bool($lookup['evidence'] ?? null)) {
                throw new InvalidArgumentException('Invalid filename observation.');
            }
            $match = $lookup['match'];
            if ($match !== null && (! is_array($match)
                || PredbSearchDocument::filenameCandidate(['_id' => $match['id'] ?? null, '_source' => $match]) === null)) {
                throw new InvalidArgumentException('Invalid filename observation candidate.');
            }
        }
    }

    public function complete(): bool
    {
        foreach ($this->lookups as $lookup) {
            if (! $lookup['complete']) {
                return false;
            }
        }

        return true;
    }

    public function hasMatches(): bool
    {
        foreach ($this->lookups as $lookup) {
            if ($lookup['match'] !== null) {
                return true;
            }
        }

        return false;
    }

    /** @return list<array{filename: string, match: array<string, mixed>}> */
    public function uniqueMatches(): array
    {
        if (! $this->complete()) {
            return [];
        }
        $selected = [];
        $identity = null;
        foreach ($this->lookups as $lookup) {
            $match = $lookup['match'];
            if ($match === null) {
                continue;
            }
            $current = (int) $match['id'].':'.PredbSearchDocument::exactValue((string) $match['title']);
            if ($identity !== null && $identity !== $current) {
                return [];
            }
            $identity = $current;
            $selected[] = ['filename' => $lookup['filename'], 'match' => $match];
        }

        return $selected;
    }
}
