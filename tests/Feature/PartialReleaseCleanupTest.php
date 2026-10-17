<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\ReleaseProcessingService;
use App\Support\Data\ProcessReleasesSettings;
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
}
