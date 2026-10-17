<?php

declare(strict_types=1);

namespace App\Services\Releases\Verification;

/** Validate all article references without fetching any payload. */
final class CandidateHeaderInventory
{
    /**
     * Header defects depend only on the manifest, so retrying the same version cannot pass. These
     * candidates wait outside the claim queue until new headers create a new version (or expire).
     */
    public const string REJECTED = 'header_rejected';

    /** Every reason assessment() gives with the REJECTED status. */
    public const array REJECTION_REASONS = [
        'Conflicting header identities require resolution.',
        'Candidate has no file inventory to spot check.',
        'Invalid or duplicate file identity in candidate headers.',
        'Invalid declared article count in candidate headers.',
        'Invalid or duplicate article reference in candidate headers.',
    ];

    /** @param array<string, mixed> $manifest */
    public function rejection(array $manifest): ?string
    {
        return $this->assessment($manifest)['reason'];
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @return array{status: 'ready'|'waiting_upload'|'header_rejected', reason: ?string}
     */
    public function assessment(array $manifest): array
    {
        if (($manifest['conflict'] ?? false) === true) {
            return ['status' => self::REJECTED, 'reason' => 'Conflicting header identities require resolution.'];
        }
        $files = $manifest['files'] ?? null;
        if (! is_array($files) || $files === []) {
            return ['status' => self::REJECTED, 'reason' => 'Candidate has no file inventory to spot check.'];
        }
        $expected = max(0, (int) ($manifest['expected_files'] ?? 0));
        $missingParts = false;
        $keys = [];
        $ids = [];
        foreach ($files as $file) {
            if (! is_array($file) || ! is_string($file['key'] ?? null) || $file['key'] === ''
                || isset($keys[$file['key']]) || ! is_array($file['segments'] ?? null)) {
                return ['status' => self::REJECTED, 'reason' => 'Invalid or duplicate file identity in candidate headers.'];
            }
            $keys[$file['key']] = true;
            $total = (int) ($file['totalparts'] ?? 0);
            if ($total < 1 || count($file['segments']) > $total) {
                return ['status' => self::REJECTED, 'reason' => 'Invalid declared article count in candidate headers.'];
            }
            $missingParts = $missingParts || count($file['segments']) < $total;
            $numbers = [];
            foreach ($file['segments'] as $segment) {
                if (! is_array($segment) || ! is_int($segment['number'] ?? null) || $segment['number'] < 1
                    || $segment['number'] > $total || isset($numbers[$segment['number']])
                    || ! is_int($segment['bytes'] ?? null) || $segment['bytes'] < 1
                    || ! is_string($segment['messageid'] ?? null)
                    || preg_match('/\A[^\s<>\x00-\x1f\x7f]+\z/D', trim($segment['messageid'], '<>')) !== 1
                    || isset($ids[trim($segment['messageid'], '<>')])) {
                    return ['status' => self::REJECTED, 'reason' => 'Invalid or duplicate article reference in candidate headers.'];
                }
                $numbers[$segment['number']] = true;
                $ids[trim($segment['messageid'], '<>')] = true;
            }
        }

        if ($expected > count($files)) {
            return ['status' => 'waiting_upload', 'reason' => 'Declared file inventory is still uploading; publication deferred.'];
        }
        if ($missingParts) {
            return ['status' => 'waiting_upload', 'reason' => 'Declared article inventory is still uploading; publication deferred.'];
        }

        return ['status' => 'ready', 'reason' => null];
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
