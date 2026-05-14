<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\ReleaseSearchIndexDocument;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ReleaseSearchIndexDocumentTest extends TestCase
{
    #[Test]
    public function normalize_for_bulk_preserves_timestamps_when_row_is_already_normalized(): void
    {
        $first = ReleaseSearchIndexDocument::normalize([
            'id' => 42,
            'name' => 'n',
            'searchname' => 's',
            'fromname' => 'f',
            'categories_id' => 1,
            'filename' => '',
            'imdbid' => '',
            'tmdbid' => 0,
            'traktid' => 0,
            'tvdb' => 0,
            'tvmaze' => 0,
            'tvrage' => 0,
            'videos_id' => 0,
            'movieinfo_id' => 0,
            'size' => 100,
            'postdate' => '2025-01-15 12:00:00',
            'adddate' => '2025-01-16 08:30:00',
            'totalpart' => 0,
            'grabs' => 0,
            'passwordstatus' => -1,
            'groups_id' => 1,
            'nzbstatus' => 1,
            'haspreview' => 0,
        ]);

        $second = ReleaseSearchIndexDocument::normalizeForBulk($first);

        self::assertSame($first['postdate_ts'], $second['postdate_ts']);
        self::assertSame($first['adddate_ts'], $second['adddate_ts']);
        self::assertGreaterThan(0, $second['postdate_ts']);
        self::assertGreaterThan(0, $second['adddate_ts']);
    }
}
