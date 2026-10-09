<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Nzb\NzbArticleFingerprint;
use App\Services\Nzb\NzbImportService;
use App\Services\Nzb\NzbService;
use App\Services\Releases\ReleaseDuplicateFinder;
use App\Support\Data\ProcessReleasesSettings;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use Tests\TestCase;
use Tests\Unit\Nzb\NzbArticleFingerprintTest;

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
            $table->string('guid')->default('');
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
    public function disabling_release_dedupe_keeps_a_same_poster_repost(): void
    {
        config(['nntmux.release_dedupe_enabled' => false]);

        [$duplicate] = (new ReleaseDuplicateFinder)->findDuplicate('Show.S01E01.1080p.WEB.h264-GRP', 'Show.S01E01.1080p.WEB.h264-GRP', 42, 1_000_000_000);

        $this->assertNull($duplicate);
    }

    #[Test]
    public function nzb_import_with_dedupe_disabled_rejects_only_identical_articles(): void
    {
        config(['nntmux.release_dedupe_enabled' => false]);
        DB::table('releases')->where('id', 1)->update(['guid' => 'stored-guid']);
        $stored = NzbArticleFingerprintTest::nzb([['a1@x', 'a2@x']]);

        $nzb = $this->createMock(NzbService::class);
        $nzb->method('readNzbContents')->willReturnCallback(static fn (string $guid): string|false => $guid === 'stored-guid' ? $stored : false);
        $this->app->instance(NzbService::class, $nzb);
        $import = new NzbImportService(['Browser' => true]);
        $find = new ReflectionMethod($import, 'findIdenticalArticleUpload');

        $same = NzbArticleFingerprint::fromContents(NzbArticleFingerprintTest::nzb([['a2@x', 'a1@x']]));
        $repost = NzbArticleFingerprint::fromContents(NzbArticleFingerprintTest::nzb([['b1@y', 'b2@y']]));

        $this->assertSame(1, $find->invoke($import, 1_000_000_000, $same)?->id);
        $this->assertNull($find->invoke($import, 1_000_000_000, $repost));
        $this->assertNull($find->invoke($import, 999, $same));
    }

    #[Test]
    public function nzb_import_finds_identical_articles_beyond_the_first_candidates(): void
    {
        config(['nntmux.release_dedupe_enabled' => false]);
        DB::table('releases')->insert(array_map(static fn (int $i): array => ['guid' => 'other-'.$i, 'name' => 'repost', 'size' => 1_000_000_000], range(1, 120)));
        DB::table('releases')->insert(['guid' => 'stored-guid', 'name' => 'repost', 'size' => 1_000_000_000]);
        $stored = NzbArticleFingerprintTest::nzb([['a1@x', 'a2@x']]);
        $other = NzbArticleFingerprintTest::nzb([['o1@z']]);

        $nzb = $this->createMock(NzbService::class);
        $nzb->method('readNzbContents')->willReturnCallback(static fn (string $guid): string => $guid === 'stored-guid' ? $stored : $other);
        $this->app->instance(NzbService::class, $nzb);
        $import = new NzbImportService(['Browser' => true]);

        $match = (new ReflectionMethod($import, 'findIdenticalArticleUpload'))->invoke($import, 1_000_000_000, NzbArticleFingerprint::fromContents($stored));

        $this->assertSame('stored-guid', $match?->guid);
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
