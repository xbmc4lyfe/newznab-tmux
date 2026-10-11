<?php

declare(strict_types=1);

namespace App\Services\Releases\Verification;

use RuntimeException;

final class NzbManifestReader
{
    /** @return array<string, mixed> */
    public function read(string $xml, object $release): array
    {
        if (strlen($xml) > 33554432) {
            throw new RuntimeException('NZB exceeds audit metadata budget.');
        }
        $previous = libxml_use_internal_errors(true);
        try {
            $document = simplexml_load_string($xml, options: LIBXML_NONET | LIBXML_NOBLANKS);
            if ($document === false || $document->getName() !== 'nzb') {
                throw new RuntimeException('Invalid NZB.');
            }
            $files = [];
            $groups = [];
            $expected = 0;
            foreach ($document->file as $index => $file) {
                $subject = (string) $file['subject'];
                if (preg_match('/[\[(](\d+)\/(\d+)[\])]/', preg_replace('/\s*\(\d+\/\d+\)\s*$/', '', $subject), $count)) {
                    $expected = max($expected, (int) $count[2]);
                }
                $parts = preg_match('/\(\d+\/(\d+)\)\s*$/', $subject, $count) ? (int) $count[1] : 0;
                $segments = [];
                foreach ($file->segments->segment as $segment) {
                    $segments[] = ['number' => (int) $segment['number'], 'bytes' => (int) $segment['bytes'], 'messageid' => trim((string) $segment)];
                }
                foreach ($file->groups->group as $group) {
                    $groups[(string) $group] = true;
                }
                $files[] = ['key' => hash('sha256', $subject), 'subject' => preg_replace('/\s*\(\d+\/\d+\)\s*$/', '', $subject),
                    'totalparts' => $parts, 'segments' => $segments];
            }
            if ($files === []) {
                throw new RuntimeException('NZB contains no files.');
            }

            return ['subject' => $release->searchname, 'fromname' => $release->fromname, 'date' => $release->postdate,
                'groups_id' => (int) $release->groups_id, 'regex_id' => 0, 'expected_files' => $expected,
                'groups' => array_keys($groups), 'files' => $files];
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }
}
