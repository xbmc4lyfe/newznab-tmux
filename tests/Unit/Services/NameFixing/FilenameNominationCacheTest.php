<?php

declare(strict_types=1);

namespace Tests\Unit\Services\NameFixing;

use App\Services\NameFixing\FilenameNominationCache;
use PHPUnit\Framework\TestCase;

final class FilenameNominationCacheTest extends TestCase
{
    public function test_row_pressure_evicts_oldest_without_truncating_current_results(): void
    {
        $cache = new FilenameNominationCache(3, 10000, 10);
        $a = [['id' => 1], ['id' => 2]];
        $b = [['id' => 3], ['id' => 4]];
        self::assertSame($a, $cache->remember('a', fn () => $a));
        self::assertSame($b, $cache->remember('b', fn () => $b));
        self::assertSame($b, $cache->remember('b', fn () => self::fail('Expected cached query')));
        self::assertSame([], $cache->remember('a', fn () => []));
    }

    public function test_oversized_result_is_complete_and_never_cached(): void
    {
        $cache = new FilenameNominationCache(1, 10000, 10);
        $rows = [['id' => 1], ['id' => 2]];
        $calls = 0;
        $resolve = function () use (&$calls, $rows): array {
            $calls++;

            return $rows;
        };
        self::assertSame($rows, $cache->remember('query', $resolve));
        self::assertSame($rows, $cache->remember('query', $resolve));
        self::assertSame(2, $calls);
    }

    public function test_payload_budget_counts_query_and_all_row_strings(): void
    {
        $cache = new FilenameNominationCache(100, 200, 10);
        $rows = [(object) ['id' => 1, 'title' => str_repeat('é', 120)]];
        self::assertSame($rows, $cache->remember('a', fn () => $rows));
        self::assertSame([], $cache->remember('a', fn () => []));
        self::assertSame([], $cache->remember(str_repeat('q', 201), fn () => []));
        self::assertSame([['id' => 9]], $cache->remember(str_repeat('q', 201), fn () => [['id' => 9]]));
    }

    public function test_empty_results_have_query_count_bound(): void
    {
        $cache = new FilenameNominationCache(100, 10000, 2);
        foreach (['a', 'b', 'c'] as $query) {
            self::assertSame([], $cache->remember($query, fn () => []));
        }
        self::assertSame([], $cache->remember('b', fn () => self::fail('Expected cached empty result')));
        self::assertSame([['id' => 1]], $cache->remember('a', fn () => [['id' => 1]]));
    }

    public function test_combined_payload_pressure_evicts_oldest_query(): void
    {
        $rows = [['title' => str_repeat('x', 30)]];
        $bytes = strlen(serialize($rows)) + 1;
        $cache = new FilenameNominationCache(100, $bytes * 2 - 1, 100);
        $cache->remember('a', fn () => $rows);
        $cache->remember('b', fn () => $rows);
        self::assertSame($rows, $cache->remember('b', fn () => self::fail('Expected cached query')));
        self::assertSame([], $cache->remember('a', fn () => []));
    }

    public function test_default_row_and_query_limits_hold_under_pressure(): void
    {
        $cache = new FilenameNominationCache;
        $rows = array_fill(0, 1000, ['id' => 7, 'title' => 'Exact.Release.2026-GRP']);
        for ($i = 0; $i < 6; $i++) {
            self::assertSame($rows, $cache->remember('query'.$i, fn () => $rows));
        }
        $entries = (new \ReflectionProperty($cache, 'entries'))->getValue($cache);
        self::assertCount(5, $entries);
        self::assertSame(5000, array_sum(array_map(fn ($entry) => count($entry['rows']), $entries)));
        self::assertArrayNotHasKey('query0', $entries);
        $empty = new FilenameNominationCache;
        for ($i = 0; $i < 5001; $i++) {
            $empty->remember('query'.$i, fn () => []);
        }
        $entries = (new \ReflectionProperty($empty, 'entries'))->getValue($empty);
        self::assertCount(5000, $entries);
        self::assertArrayNotHasKey('query0', $entries);
    }
}
