<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Releases\ReleaseDuplicateFinder;
use App\Support\Data\ProcessReleasesSettings;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ReleaseDuplicateFinderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
            'nntmux.release_dedupe_enabled' => true,
            'nntmux.release_dedupe_size_tolerance' => 0.05,
        ]);

        DB::purge();
        DB::reconnect();

        Schema::create('releases', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('name')->default('');
            $table->string('searchname')->default('');
            $table->string('fromname')->nullable();
            $table->unsignedInteger('predb_id')->default(0);
            $table->unsignedBigInteger('size')->default(0);
        });

        DB::table('releases')->insert([
            'name' => 'Show.S01E01.1080p.WEB.h264-GRP',
            'searchname' => 'Show.S01E01.1080p.WEB.h264-GRP',
            'fromname' => 'poster-a@example.com',
            'predb_id' => 42,
            'size' => 1_000_000_000,
        ]);
    }

    #[Test]
    public function an_existing_release_with_the_same_searchname_and_size_is_a_duplicate_by_default(): void
    {
        [$duplicate, $reason] = (new ReleaseDuplicateFinder)->findDuplicate('Show.S01E01.1080p.WEB.h264-GRP', 'Show.S01E01.1080p.WEB.h264-GRP', 42, 1_000_000_000);

        $this->assertNotNull($duplicate);
        $this->assertSame('predb_id_match', $reason);
    }

    #[Test]
    public function disabling_release_dedupe_keeps_every_upload(): void
    {
        config(['nntmux.release_dedupe_enabled' => false]);

        [$duplicate, $reason] = (new ReleaseDuplicateFinder)->findDuplicate('Show.S01E01.1080p.WEB.h264-GRP', 'Show.S01E01.1080p.WEB.h264-GRP', 42, 1_000_000_000);

        $this->assertNull($duplicate);
        $this->assertNull($reason);
    }

    #[Test]
    public function disabling_release_dedupe_also_disables_cross_post_cleanup(): void
    {
        $settings = new ProcessReleasesSettings(crossPostTime: 2);
        $this->assertTrue($settings->hasCrossPostDetection());

        config(['nntmux.release_dedupe_enabled' => false]);

        $this->assertFalse($settings->hasCrossPostDetection());
    }
}
