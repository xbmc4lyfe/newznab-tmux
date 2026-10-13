<?php

declare(strict_types=1);

namespace App\Services\Releases\Verification;

final class SpotCheckPlanner
{
    /**
     * Select a stable, bounded sample and spread first across different files.
     * A stable candidate identity keeps retries from repeatedly sampling a new set.
     *
     * @param  array<string, mixed>  $manifest
     * @return list<array{file_key:string,totalparts:int,totalparts_inferred:bool,number:int,messageid:string,bytes:int}>
     */
    public function select(array $manifest, int $maximumArticles, int $maximumHeaderBytes, ?int $maximumArticleBytes = null): array
    {
        if ($maximumArticles < 1 || $maximumHeaderBytes < 1 || ! is_array($manifest['files'] ?? null)) {
            return [];
        }

        $files = [];
        foreach ($manifest['files'] as $file) {
            if (! is_array($file) || ! is_string($file['key'] ?? null) || ! is_array($file['segments'] ?? null)) {
                continue;
            }
            $segments = [];
            foreach ($file['segments'] as $segment) {
                if (! is_array($segment) || ! is_string($segment['messageid'] ?? null)
                    || $segment['messageid'] === '' || ! is_numeric($segment['number'] ?? null)
                    || ! is_numeric($segment['bytes'] ?? null)) {
                    continue;
                }
                $bytes = (int) $segment['bytes'];
                $number = (int) $segment['number'];
                if ($number < 1 || $bytes < 1 || $bytes > min($maximumHeaderBytes, $maximumArticleBytes ?? $maximumHeaderBytes)) {
                    continue;
                }
                $segments[] = ['file_key' => $file['key'], 'totalparts' => max(0, (int) ($file['totalparts'] ?? 0)),
                    'totalparts_inferred' => ($file['totalparts_inferred'] ?? false) === true,
                    'number' => $number, 'messageid' => $segment['messageid'], 'bytes' => $bytes];
            }
            if ($segments !== []) {
                $identity = (string) ($manifest['identity'] ?? '');
                usort($segments, static fn (array $a, array $b): int => hash('sha256', $identity."\0".$a['messageid'])
                    <=> hash('sha256', $identity."\0".$b['messageid']));
                $files[$file['key']] = $segments;
            }
        }

        if ($files === []) {
            return [];
        }

        $identity = (string) ($manifest['identity'] ?? '');
        $keys = array_keys($files);
        usort($keys, static fn (string $a, string $b): int => hash('sha256', $identity."\0".$a)
            <=> hash('sha256', $identity."\0".$b));

        $selected = [];
        $seen = [];
        $bytes = 0;
        for ($round = 0; count($selected) < $maximumArticles; $round++) {
            $added = false;
            foreach ($keys as $key) {
                if (! isset($files[$key][$round])) {
                    continue;
                }
                $segment = $files[$key][$round];
                if (isset($seen[$segment['messageid']]) || $bytes + $segment['bytes'] > $maximumHeaderBytes) {
                    continue;
                }
                $selected[] = $segment;
                $seen[$segment['messageid']] = true;
                $bytes += $segment['bytes'];
                $added = true;
                if (count($selected) >= $maximumArticles) {
                    break;
                }
            }
            if (! $added) {
                break;
            }
        }

        return $selected;
    }
}
