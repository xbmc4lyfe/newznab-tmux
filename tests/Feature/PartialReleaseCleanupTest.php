<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Nzb\NzbService;
use App\Services\ReleaseImageService;
use App\Services\ReleaseProcessingService;
use App\Services\Releases\ReleaseManagementService;
use App\Support\Data\ProcessReleasesSettings;
use App\Support\Data\ReleaseDeleteStats;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PartialReleaseCleanupTest extends TestCase
{
    public function test_completion_cleanup_keeps_partial_publications(): void
    {
        Schema::dropIfExists('releases');
        Schema::create('releases', function (Blueprint $table): void {
            $table->id();
            $table->string('guid');
            $table->double('completion')->default(0);
            $table->unsignedInteger('articles_found')->nullable();
            $table->unsignedInteger('articles_expected')->nullable();
        });
        DB::table('releases')->insert([
            ['id' => 1, 'guid' => 'incomplete', 'completion' => 50, 'articles_found' => null, 'articles_expected' => null],
            ['id' => 2, 'guid' => 'partial', 'completion' => 50, 'articles_found' => 236, 'articles_expected' => 934],
            ['id' => 3, 'guid' => 'complete', 'completion' => 100, 'articles_found' => null, 'articles_expected' => null],
        ]);
        // Settings normally load from the configuration tables; the query only needs the threshold.
        $service = (new \ReflectionClass(ReleaseProcessingService::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty(ReleaseProcessingService::class, 'settings'))->setValue($service, new ProcessReleasesSettings(completion: 90));
        $ids = (new \ReflectionMethod(ReleaseProcessingService::class, 'incompleteReleases'))->invoke($service)
            ->orderBy('id')->pluck('id')->all();

        $this->assertSame([1], array_map('intval', $ids));
    }

    public function test_category_minimum_size_cleanup_keeps_partial_publications(): void
    {
        Schema::dropIfExists('releases');
        Schema::create('releases', function (Blueprint $table): void {
            $table->id();
            $table->string('guid');
            $table->integer('categories_id');
            $table->bigInteger('size');
            $table->unsignedInteger('articles_expected')->nullable();
        });
        Schema::dropIfExists('categories');
        Schema::create('categories', function (Blueprint $table): void {
            $table->id();
            $table->bigInteger('minsizetoformrelease')->default(0);
        });
        DB::table('categories')->insert(['id' => 2000, 'minsizetoformrelease' => 100]);
        DB::table('releases')->insert([
            ['id' => 1, 'guid' => 'small', 'categories_id' => 2000, 'size' => 10, 'articles_expected' => null],
            ['id' => 2, 'guid' => 'partial', 'categories_id' => 2000, 'size' => 10, 'articles_expected' => 934],
        ]);
        $management = \Mockery::mock(ReleaseManagementService::class);
        $management->shouldReceive('deleteSingle')->once()->withArgs(fn (array $identifiers): bool => $identifiers['g'] === 'small');
        $service = (new \ReflectionClass(ReleaseProcessingService::class))->newInstanceWithoutConstructor();
        foreach (['releaseManagement' => $management, 'nzb' => \Mockery::mock(NzbService::class),
            'releaseImage' => \Mockery::mock(ReleaseImageService::class)] as $property => $value) {
            (new \ReflectionProperty(ReleaseProcessingService::class, $property))->setValue($service, $value);
        }

        $stats = (new \ReflectionMethod(ReleaseProcessingService::class, 'deleteCategoryMinSizeReleases'))->invoke($service, new ReleaseDeleteStats);

        $this->assertSame(1, $stats->categoryMinSize);
    }
}
