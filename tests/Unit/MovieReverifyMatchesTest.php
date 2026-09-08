<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Release;
use App\Services\MovieService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;

class MovieReverifyMatchesTest extends ImdbScraperTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('movieinfo');
        Schema::create('movieinfo', function (Blueprint $table): void {
            $table->id();
            $table->string('imdbid')->unique();
            $table->unsignedInteger('tmdbid')->default(0);
            $table->unsignedInteger('traktid')->default(0);
            $table->string('title')->default('');
            $table->string('tagline')->default('');
            $table->string('rating', 4)->default('');
            $table->string('rtrating', 10)->default('');
            $table->string('plot')->default('');
            $table->string('year', 4)->default('');
            $table->string('genre', 64)->default('');
            $table->string('type', 32)->default('');
            $table->string('director', 64)->default('');
            $table->text('actors')->default('');
            $table->string('language', 64)->default('');
            $table->boolean('cover')->default(false);
            $table->boolean('backdrop')->default(false);
            $table->string('trailer')->default('');
            $table->timestamps();
        });

        Schema::dropIfExists('releases');
        Schema::create('releases', function (Blueprint $table): void {
            $table->id();
            $table->string('searchname')->default('');
            $table->unsignedInteger('categories_id')->default(0);
            $table->string('imdbid')->nullable();
            $table->unsignedBigInteger('movieinfo_id')->nullable();
        });
    }

    #[Test]
    public function it_resets_releases_whose_imdb_match_no_longer_verifies(): void
    {
        Cache::flush();

        $movieService = new MovieService;
        $movieService->update([
            'imdbid' => '13622970',
            'title' => 'Moana 2',
            'year' => '2024',
        ]);
        $movieService->update([
            'imdbid' => '39369643',
            'title' => 'State of Fear',
            'year' => '2026',
        ]);

        Release::query()->insert([
            [
                'id' => 11,
                'searchname' => 'Moana.2026.1080p.AMZN.WEB-DL.H.264-HDShare',
                'categories_id' => 2000,
                'imdbid' => '13622970',
                'movieinfo_id' => 1,
            ],
            [
                'id' => 12,
                'searchname' => 'Moana.2.2024.1080p.WEB-DL.x265-Group',
                'categories_id' => 2000,
                'imdbid' => '13622970',
                'movieinfo_id' => 1,
            ],
            [
                'id' => 13,
                'searchname' => 'Salve.Geral.Irmandade.2026.2160p.Netflix.WEB-DL.HEVC.10bit.DDP5.1.Atmos.6Audios-QHstudIo',
                'categories_id' => 2000,
                'imdbid' => '39369643',
                'movieinfo_id' => 2,
            ],
        ]);

        $this->artisan('movie:reverify-matches')->assertSuccessful();

        $this->assertNull(Release::query()->whereKey(11)->value('imdbid'));
        $this->assertNull(Release::query()->whereKey(11)->value('movieinfo_id'));
        $this->assertSame('13622970', Release::query()->whereKey(12)->value('imdbid'));
        // Alternate/international title with matching year must be kept.
        $this->assertSame('39369643', Release::query()->whereKey(13)->value('imdbid'));
    }

    #[Test]
    public function it_only_reports_mismatches_in_dry_run_mode(): void
    {
        Cache::flush();

        $movieService = new MovieService;
        $movieService->update([
            'imdbid' => '13622970',
            'title' => 'Moana 2',
            'year' => '2024',
        ]);

        Release::query()->insert([
            'id' => 21,
            'searchname' => 'Moana.2026.1080p.AMZN.WEB-DL.H.264-HDShare',
            'categories_id' => 2000,
            'imdbid' => '13622970',
            'movieinfo_id' => 1,
        ]);

        $this->artisan('movie:reverify-matches', ['--dry-run' => true])
            ->expectsOutputToContain('1 mismatches')
            ->assertSuccessful();

        $this->assertSame('13622970', Release::query()->whereKey(21)->value('imdbid'));
    }
}
