<?php

declare(strict_types=1);

namespace App\Services\Releases\Verification;

/** Validate all article references without fetching any payload. */
final class CandidateHeaderInventory
{
    /** @param array<string, mixed> $manifest */
    public function rejection(array $manifest): ?string
    {
        $files = $manifest['files'] ?? null;
        if (! is_array($files) || $files === []) {
            return 'Candidate has no file inventory to spot check.';
        }
        $expected = max(0, (int) ($manifest['expected_files'] ?? 0));
        if ($expected > count($files)) {
            return 'Declared file inventory is still uploading; publication deferred.';
        }
        $keys = [];
        $ids = [];
        foreach ($files as $file) {
            if (! is_array($file) || ! is_string($file['key'] ?? null) || $file['key'] === ''
                || isset($keys[$file['key']]) || ! is_array($file['segments'] ?? null)) {
                return 'Invalid or duplicate file identity in candidate headers.';
            }
            $keys[$file['key']] = true;
            $total = (int) ($file['totalparts'] ?? 0);
            if ($total < 1 || count($file['segments']) !== $total) {
                return 'Declared article inventory is still uploading; publication deferred.';
            }
            $numbers = [];
            foreach ($file['segments'] as $segment) {
                if (! is_array($segment) || ! is_int($segment['number'] ?? null) || $segment['number'] < 1
                    || $segment['number'] > $total || isset($numbers[$segment['number']])
                    || ! is_int($segment['bytes'] ?? null) || $segment['bytes'] < 1
                    || ! is_string($segment['messageid'] ?? null)
                    || preg_match('/\A[^\s<>\x00-\x1f\x7f]+\z/D', trim($segment['messageid'], '<>')) !== 1
                    || isset($ids[trim($segment['messageid'], '<>')])) {
                    return 'Invalid or duplicate article reference in candidate headers.';
                }
                $numbers[$segment['number']] = true;
                $ids[trim($segment['messageid'], '<>')] = true;
            }
        }

        return null;
    }

    /** @param array<string, mixed> $manifest */
    public function bytes(array $manifest): int
    {
        $bytes = 0;
        foreach ($manifest['files'] as $file) {
            foreach ($file['segments'] as $segment) {
                $bytes += max(0, (int) ($segment['bytes'] ?? 0));
            }
        }

        return $bytes;
    }
}
