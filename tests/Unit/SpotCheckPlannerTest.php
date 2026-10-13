<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Releases\Verification\SpotCheckPlanner;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SpotCheckPlannerTest extends TestCase
{
    #[Test]
    public function it_selects_a_stable_bounded_sample_spread_across_files(): void
    {
        $manifest = [
            'identity' => str_repeat('a', 40),
            'files' => [
                ['key' => 'file-a', 'segments' => [
                    ['number' => 1, 'messageid' => 'a-1', 'bytes' => 100],
                    ['number' => 2, 'messageid' => 'a-2', 'bytes' => 100],
                ]],
                ['key' => 'file-b', 'segments' => [
                    ['number' => 1, 'messageid' => 'b-1', 'bytes' => 100],
                    ['number' => 2, 'messageid' => 'b-2', 'bytes' => 100],
                ]],
                ['key' => 'file-c', 'segments' => [
                    ['number' => 1, 'messageid' => 'c-1', 'bytes' => 100],
                ]],
            ],
        ];

        $planner = new SpotCheckPlanner;
        $first = $planner->select($manifest, 3, 300);

        self::assertCount(3, $first);
        self::assertSame($first, $planner->select($manifest, 3, 300));
        self::assertCount(3, array_unique(array_column($first, 'messageid')));
        self::assertCount(3, array_unique(array_column($first, 'file_key')));
    }

    #[Test]
    public function it_respects_the_article_count_and_header_byte_budgets(): void
    {
        $manifest = [
            'identity' => str_repeat('b', 40),
            'files' => [
                ['key' => 'large', 'segments' => [
                    ['number' => 1, 'messageid' => 'too-large', 'bytes' => 900],
                    ['number' => 2, 'messageid' => 'fits', 'bytes' => 100],
                ]],
                ['key' => 'small', 'segments' => [
                    ['number' => 1, 'messageid' => 'also-fits', 'bytes' => 100],
                ]],
            ],
        ];

        $sample = (new SpotCheckPlanner)->select($manifest, 1, 500);

        self::assertCount(1, $sample);
        self::assertLessThanOrEqual(500, $sample[0]['bytes']);
        self::assertNotSame('too-large', $sample[0]['messageid']);
    }

    #[Test]
    public function it_returns_no_sample_when_the_manifest_has_no_bounded_articles(): void
    {
        self::assertSame([], (new SpotCheckPlanner)->select(['identity' => 'x', 'files' => []], 3, 1024));
    }
}
