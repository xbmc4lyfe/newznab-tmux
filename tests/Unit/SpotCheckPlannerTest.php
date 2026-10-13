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

    #[Test]
    public function it_preserves_retry_samples_with_inferred_endpoints_duplicates_and_byte_limits(): void
    {
        $manifest = ['identity' => 'stable-retry', 'files' => [
            ['key' => 'declared', 'totalparts' => 3, 'segments' => [
                ['number' => 1, 'messageid' => 'duplicate', 'bytes' => 100],
                ['number' => 2, 'messageid' => 'declared-2', 'bytes' => 200],
                ['number' => 3, 'messageid' => 'declared-3', 'bytes' => 100],
            ]],
            ['key' => 'inferred-a', 'totalparts' => 3, 'totalparts_inferred' => true, 'segments' => [
                ['number' => 1, 'messageid' => 'inferred-a-1', 'bytes' => 100],
                ['number' => 2, 'messageid' => 'inferred-a-2', 'bytes' => 200],
                ['number' => 3, 'messageid' => 'inferred-a-end', 'bytes' => 100],
            ]],
            ['key' => 'inferred-b', 'totalparts' => 2, 'totalparts_inferred' => true, 'segments' => [
                ['number' => 1, 'messageid' => 'duplicate', 'bytes' => 100],
                ['number' => 2, 'messageid' => 'inferred-b-end', 'bytes' => 100],
            ]],
            ['key' => 'excluded', 'totalparts' => 1, 'segments' => [
                ['number' => 1, 'messageid' => 'oversize', 'bytes' => 9999],
                ['number' => 0, 'messageid' => 'invalid', 'bytes' => 50],
            ]],
        ]];
        $cases = [
            [1, 1000, 1000, ['inferred-b-end']],
            [4, 1000, 1000, ['inferred-b-end', 'inferred-a-end', 'duplicate', 'inferred-a-1']],
            [16, 350, 1000, ['inferred-b-end', 'inferred-a-end', 'duplicate']],
            [16, 1000, 100, ['inferred-b-end', 'inferred-a-end', 'duplicate', 'inferred-a-1', 'declared-3']],
            [16, 0, 100, []],
        ];
        foreach ($cases as [$articles, $bytes, $articleBytes, $expected]) {
            $sample = (new SpotCheckPlanner)->select($manifest, $articles, $bytes, $articleBytes);
            self::assertSame($expected, array_column($sample, 'messageid'));
            foreach ($sample as $segment) {
                self::assertSame(['file_key', 'totalparts', 'totalparts_inferred', 'number', 'messageid', 'bytes'], array_keys($segment));
            }
        }
    }

    #[Test]
    public function it_does_not_evaluate_identity_when_no_article_is_eligible(): void
    {
        $planner = new SpotCheckPlanner;
        $identity = new \stdClass;
        self::assertSame([], $planner->select(['identity' => $identity, 'files' => []], 1, 100));
        self::assertSame([], $planner->select(['identity' => $identity, 'files' => [
            ['key' => 'filtered', 'segments' => [
                ['number' => 1, 'messageid' => 'too-large', 'bytes' => 101],
            ]],
        ]], 1, 100));
    }
}
