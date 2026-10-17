<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Api\V1\ReleaseItemPayloadBuilder;
use Tests\TestCase;

class PartialReleaseApiAttributesTest extends TestCase
{
    public function test_extended_attributes_report_found_and_declared_articles_only_for_partial_releases(): void
    {
        $builder = new ReleaseItemPayloadBuilder(['extended' => '1', 'token' => 'token'], ['server' => ['url' => 'https://indexer.invalid']]);
        $release = ['guid' => 'guid', 'searchname' => 'Movie', 'adddate' => '2026-10-17 00:00:00', 'postdate' => '2026-10-17 00:00:00',
            'categories_id' => 2000, 'size' => 1000, 'totalpart' => 2, 'category_name' => 'Movies > HD'];

        $complete = $builder->build($release)['attr'];
        $partial = $builder->build($release + ['articles_found' => 236, 'articles_expected' => 934])['attr'];

        $this->assertArrayNotHasKey('articles_expected', $complete);
        $this->assertSame(236, $partial['articles_found']);
        $this->assertSame(934, $partial['articles_expected']);
    }
}
