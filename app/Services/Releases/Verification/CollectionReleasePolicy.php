<?php

declare(strict_types=1);

namespace App\Services\Releases\Verification;

use App\Services\Configuration\ConfigurationProvider;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class CollectionReleasePolicy
{
    private const VERIFIED_OVERSIZE = 'Verified payload exceeds the configured maximum size.';

    private const DECLARED_OVERSIZE = 'Declared collection size exceeds the configured maximum.';

    /** A rejection more articles cannot cure: a collection's size only grows as they arrive. */
    public static function irrecoverable(string $reason): bool
    {
        return $reason === self::VERIFIED_OVERSIZE || $reason === self::DECLARED_OVERSIZE;
    }

    /** @param array<string, mixed> $manifest */
    public function verifiedRejection(array $manifest): ?string
    {
        if (($manifest['metadata_source'] ?? null) === 'import') {
            return null;
        }
        if (($manifest['verification_mode'] ?? null) === 'spot_check') {
            // The publisher has already validated a partial block against the locked candidate.
            $inventory = new CandidateHeaderInventory;
            if (($rejection = isset($manifest['partial']) ? $inventory->partialRejection($manifest) : $inventory->rejection($manifest)) !== null) {
                throw new RuntimeException($rejection);
            }

            return $this->spotCheckRejection($manifest);
        }
        $bytes = $manifest['verified_bytes'] ?? null;
        $names = $manifest['verified_file_names'] ?? null;
        if (! is_int($bytes) || $bytes < 0 || ! is_array($names) || ! array_is_list($names) || $names === []) {
            throw new RuntimeException('Authoritative verified size and inventory are required for collection publication.');
        }
        foreach ($names as $name) {
            if (! is_string($name) || $name === '') {
                throw new RuntimeException('Authoritative verified inventory contains an invalid filename.');
            }
        }
        $names = array_values(array_unique($names));
        $limits = $this->limits((int) $manifest['groups_id']);
        if ($bytes < $limits['min_size']) {
            return 'Verified payload is below the configured minimum size.';
        }
        if ($limits['max_size'] > 0 && $bytes > $limits['max_size']) {
            return self::VERIFIED_OVERSIZE;
        }
        if (count($names) < $limits['min_files']) {
            return 'Verified inventory is below the configured minimum file count.';
        }
        if (array_filter($names, static fn (string $name): bool => preg_match('/\.par2\z/i', $name) !== 1) === []) {
            return 'Verified inventory contains only PAR2 files without a proved source payload.';
        }

        return null;
    }

    /** @param array<string, mixed> $manifest */
    private function spotCheckRejection(array $manifest): ?string
    {
        $sample = $manifest['spot_check'] ?? null;
        if (! is_array($sample) || (int) ($sample['articles'] ?? 0) < 1
            || (int) ($sample['bytes'] ?? PHP_INT_MAX) > (int) config('release_verification.spot_check_bytes', 1048576)) {
            return 'A bounded article spot check did not complete.';
        }
        $bytes = (new CandidateHeaderInventory)->bytes($manifest);
        // Only files with articles are written to the NZB; a partial upload can declare files it never posted.
        $emitted = array_values(array_filter($manifest['files'] ?? [], static fn (mixed $file): bool => is_array($file)
            && is_array($file['segments'] ?? null) && $file['segments'] !== []));
        $fileCount = count($emitted);
        $limits = $this->limits((int) $manifest['groups_id']);
        if ($bytes < $limits['min_size']) {
            return 'Declared collection size is below the configured minimum.';
        }
        if ($limits['max_size'] > 0 && $bytes > $limits['max_size']) {
            return self::DECLARED_OVERSIZE;
        }
        if ($fileCount < $limits['min_files']) {
            return 'Declared file count is below the configured minimum.';
        }

        $subjects = array_map(static fn (array $file): string => (string) ($file['subject'] ?? ''), $emitted);
        $hasPayload = array_filter($subjects,
            static fn (string $subject): bool => preg_match('/\.par2(?:["\'\s]|$)/i', $subject) !== 1) !== [];
        // An obfuscated, extensionless body name is not evidence of a source payload.
        $sampledNames = array_filter($manifest['verified_file_names'] ?? [],
            static fn (mixed $name): bool => is_string($name) && HeaderFilenameIdentity::provesPayload($name));
        if (! $hasPayload && $sampledNames === []) {
            return 'Header inventory contains only PAR2 files without a declared source payload.';
        }

        return null;
    }

    /** @return array{min_size:int,max_size:int,min_files:int} */
    private function limits(int $groupId): array
    {
        $settings = app(ConfigurationProvider::class)->ingestion();
        $group = DB::table('usenet_groups')->where('id', $groupId)->first();

        return ['min_size' => max(0, (int) ($group->minsizetoformrelease ?? 0), $settings->minSizeToFormRelease),
            'max_size' => max(0, $settings->maxSizeToFormRelease),
            'min_files' => max(0, (int) ($group->minfilestoformrelease ?? 0), $settings->minFilesToFormRelease)];
    }
}
