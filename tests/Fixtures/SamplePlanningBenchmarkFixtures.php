<?php

declare(strict_types=1);

namespace Tests\Fixtures;

final class SamplePlanningBenchmarkFixtures
{
    /** @return array<string, array{articles:int,kind:string,native:bool,category:string}> */
    public static function strata(): array
    {
        return [
            'small-dispatch' => ['articles' => 128, 'kind' => 'ordinary', 'native' => false, 'category' => 'dispatch_control'],
            'boundary-1024' => ['articles' => 1024, 'kind' => 'ordinary', 'native' => true, 'category' => 'common'],
            'large-16384' => ['articles' => 16384, 'kind' => 'ordinary', 'native' => true, 'category' => 'common'],
            'duplicates-inferred' => ['articles' => 16384, 'kind' => 'inferred', 'native' => true, 'category' => 'uncommon'],
            'tight-budget' => ['articles' => 16384, 'kind' => 'tight', 'native' => true, 'category' => 'uncommon'],
            'unsupported-coercion' => ['articles' => 1024, 'kind' => 'coercion', 'native' => false, 'category' => 'fallback_control'],
        ];
    }

    /** @return array{manifest:array<string,mixed>,maximum_articles:int,maximum_header_bytes:int,maximum_article_bytes:?int,expected_count:int,kind:string,variant:int} */
    public static function make(string $stratum, int $variant): array
    {
        $definition = self::strata()[$stratum] ?? throw new \InvalidArgumentException('Unknown sample-planning stratum.');
        $kind = $definition['kind'];
        $files = [];
        $perFile = intdiv($definition['articles'], 32);
        for ($file = 0; $file < 32; $file++) {
            $segments = [];
            for ($part = 1; $part <= $perFile; $part++) {
                $message = 'sample-'.$variant.'-'.$file.'-'.$part;
                if ($kind === 'inferred' && $part === 1) {
                    $message = 'shared-'.$variant;
                }
                if ($kind === 'inferred' && $part === $perFile && $file < 16) {
                    $message = 'shared-terminal-'.$variant;
                }
                $segments[] = ['number' => $part, 'messageid' => $message,
                    'bytes' => $kind === 'tight' && $part % 7 === 0 ? 257 : 125];
            }
            $files[] = ['key' => 'file-'.$file, 'totalparts' => $perFile,
                'totalparts_inferred' => $kind === 'inferred', 'segments' => $segments];
        }
        if ($kind === 'coercion') {
            $files[0]['segments'][0]['bytes'] = '125';
        }

        return ['manifest' => ['identity' => 'frozen-sample-'.$variant, 'files' => $files],
            'maximum_articles' => 16, 'maximum_header_bytes' => $kind === 'tight' ? 375 : 2000,
            'maximum_article_bytes' => $kind === 'tight' ? 125 : null,
            'expected_count' => $kind === 'tight' ? 3 : 16, 'kind' => $kind, 'variant' => $variant];
    }

    /**
     * Properties declared by fixture construction independently of either planner.
     *
     * @param  array<string,mixed>  $fixture
     * @param  array<mixed>  $sample
     */
    public static function correct(array $fixture, array $sample): bool
    {
        if (! array_is_list($sample) || count($sample) !== $fixture['expected_count']) {
            return false;
        }
        $seen = [];
        $files = [];
        $used = 0;
        foreach ($sample as $row) {
            if (! is_array($row) || array_keys($row) !== ['file_key', 'totalparts', 'totalparts_inferred', 'number', 'messageid', 'bytes']
                || ! is_string($row['file_key']) || ! is_string($row['messageid']) || ! is_int($row['bytes']) || ! is_int($row['number'])
                || ! is_int($row['totalparts']) || ! is_bool($row['totalparts_inferred']) || isset($seen[$row['messageid']])
                || isset($files[$row['file_key']]) || $row['bytes'] !== 125 || $row['number'] < 1) {
                return false;
            }
            $file = null;
            foreach ($fixture['manifest']['files'] as $candidate) {
                if ($candidate['key'] === $row['file_key']) {
                    $file = $candidate;
                    break;
                }
            }
            if ($file === null || $row['totalparts'] !== $file['totalparts'] || $row['totalparts_inferred'] !== $file['totalparts_inferred']) {
                return false;
            }
            $segment = $file['segments'][$row['number'] - 1] ?? null;
            if ($segment === null || $segment['messageid'] !== $row['messageid'] || (int) $segment['bytes'] !== $row['bytes']) {
                return false;
            }
            if ($fixture['kind'] === 'inferred' && $row['number'] !== $row['totalparts']) {
                return false;
            }
            $seen[$row['messageid']] = true;
            $files[$row['file_key']] = true;
            $used += $row['bytes'];
        }

        return $used <= $fixture['maximum_header_bytes'];
    }
}
