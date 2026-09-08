<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\Release;
use App\Services\MovieService;
use App\Services\TraktService;
use App\Services\TvProcessing\Providers\TraktProvider;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\Unit\ImdbScraperTestCase;

class MovieServiceTest extends ImdbScraperTestCase
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
    public function it_accepts_numeric_trakt_years_when_matching_movie_metadata(): void
    {
        Cache::flush();

        $service = $this->makeMovieServiceForTraktResponse([
            'title' => 'Example Movie',
            'year' => 2024,
            'ids' => ['trakt' => 12345],
            'overview' => 'Test overview',
            'tagline' => 'Test tagline',
            'genres' => ['Drama'],
            'rating' => 7.5,
            'votes' => 10,
            'language' => 'en',
            'runtime' => 100,
            'trailer' => '',
        ]);

        $this->setMovieServiceProperty($service, 'currentTitle', 'Example Movie');
        $this->setMovieServiceProperty($service, 'currentYear', '2024');

        $movie = $service->fetchTraktTVProperties('8169446');

        $this->assertIsArray($movie);
        $this->assertSame('Example Movie', $movie['title']);
        $this->assertSame(2024, $movie['year']);
    }

    #[Test]
    public function it_still_rejects_mismatched_numeric_trakt_years(): void
    {
        Cache::flush();

        $service = $this->makeMovieServiceForTraktResponse([
            'title' => 'Example Movie',
            'year' => 2024,
            'ids' => ['trakt' => 12345],
        ]);

        $this->setMovieServiceProperty($service, 'currentTitle', 'Example Movie');
        $this->setMovieServiceProperty($service, 'currentYear', '2023');

        $this->assertFalse($service->fetchTraktTVProperties('8169447'));
    }

    #[Test]
    public function it_finds_movie_info_for_imdb_ids_with_meaningful_leading_zeroes(): void
    {
        Cache::flush();

        $service = new MovieService;
        $service->echooutput = false;

        $service->update([
            'imdbid' => '0137523',
            'title' => 'Example Movie',
            'year' => '2024',
        ]);

        $movie = $service->getMovieInfo('0137523');

        $this->assertNotNull($movie);
        $this->assertSame('0137523', $movie->imdbid);
        $this->assertSame('Example Movie', $movie->title);
    }

    #[Test]
    public function it_returns_existing_trailer_for_imdb_ids_with_meaningful_leading_zeroes(): void
    {
        Cache::flush();

        $service = new MovieService;
        $service->echooutput = false;

        $service->update([
            'imdbid' => '0137523',
            'title' => 'Example Movie',
            'year' => '2024',
            'trailer' => 'https://example.test/embed/trailer',
        ]);

        $this->assertSame('https://example.test/embed/trailer', $service->getTrailer('0137523'));
    }

    #[Test]
    public function it_distinguishes_pending_movie_lookup_sentinels_from_failed_empty_values(): void
    {
        $this->assertTrue(imdb_id_needs_lookup(null));
        $this->assertTrue(imdb_id_needs_lookup('0'));
        $this->assertTrue(imdb_id_needs_lookup('0000000'));
        $this->assertTrue(imdb_id_needs_lookup('00000000'));
        $this->assertFalse(imdb_id_needs_lookup(''));
        $this->assertFalse(imdb_id_needs_lookup('0137523'));
    }

    #[Test]
    public function it_keeps_a_found_imdb_id_when_metadata_refresh_fails(): void
    {
        Cache::flush();

        $service = new class extends MovieService
        {
            public function updateMovieInfo(string $imdbId): bool
            {
                return false;
            }
        };
        $service->echooutput = false;

        Release::query()->insert([
            'id' => 2,
            'searchname' => 'Example.Movie.2024',
            'categories_id' => 2000,
            'imdbid' => null,
            'movieinfo_id' => null,
        ]);

        $result = $service->doMovieUpdate('tt0137523', 'IMDb(scrape)', 2);

        $this->assertSame('0137523', $result);
        $this->assertSame('0137523', Release::query()->whereKey(2)->value('imdbid'));
        $this->assertNull(Release::query()->whereKey(2)->value('movieinfo_id'));
    }

    #[Test]
    public function it_does_not_match_sequels_from_adjacent_years_in_local_search(): void
    {
        Cache::flush();

        $service = new MovieService;
        $service->echooutput = false;
        $service->update([
            'imdbid' => '13622970',
            'title' => 'Moana 2',
            'year' => '2024',
        ]);

        $this->setMovieServiceProperty($service, 'currentTitle', 'Moana');
        $this->setMovieServiceProperty($service, 'currentYear', '2026');

        $this->assertFalse($this->invokeLocalIMDBSearch($service));
    }

    #[Test]
    public function it_prefers_the_exact_year_title_match_in_local_search(): void
    {
        Cache::flush();

        $service = new MovieService;
        $service->echooutput = false;
        $service->update([
            'imdbid' => '13622970',
            'title' => 'Moana 2',
            'year' => '2024',
        ]);
        $service->update([
            'imdbid' => '25347582',
            'title' => 'Moana',
            'year' => '2026',
        ]);

        $this->setMovieServiceProperty($service, 'currentTitle', 'Moana');
        $this->setMovieServiceProperty($service, 'currentYear', '2026');

        $this->assertSame('25347582', $this->invokeLocalIMDBSearch($service));
    }

    #[Test]
    public function it_rejects_same_title_candidates_from_a_different_year_in_local_search(): void
    {
        Cache::flush();

        $service = new MovieService;
        $service->echooutput = false;
        $service->update([
            'imdbid' => '0137523',
            'title' => 'Example Movie',
            'year' => '2023',
        ]);

        $this->setMovieServiceProperty($service, 'currentTitle', 'Example Movie');
        $this->setMovieServiceProperty($service, 'currentYear', '2024');

        $this->assertFalse($this->invokeLocalIMDBSearch($service));
    }

    #[Test]
    public function it_accepts_a_candidate_with_an_alternate_title_when_the_year_matches(): void
    {
        Cache::flush();

        $service = new MovieService;
        $service->echooutput = false;
        $service->update([
            'imdbid' => '39369643',
            'title' => 'State of Fear',
            'year' => '2026',
        ]);

        Release::query()->insert([
            'id' => 5,
            'searchname' => 'Salve.Geral.Irmandade.2026.2160p.Netflix.WEB-DL.HEVC.10bit.DDP5.1.Atmos.6Audios-QHstudIo',
            'categories_id' => 2000,
            'imdbid' => null,
            'movieinfo_id' => null,
        ]);

        $this->setMovieServiceProperty($service, 'currentTitle', 'Salve Geral Irmandade');
        $this->setMovieServiceProperty($service, 'currentYear', '2026');

        $this->assertSame('39369643', $service->doMovieUpdate('tt39369643', 'IMDb(scrape)', 5));
        $this->assertSame('39369643', Release::query()->whereKey(5)->value('imdbid'));
    }

    #[Test]
    public function it_rejects_a_candidate_whose_local_movie_info_year_mismatches(): void
    {
        Cache::flush();

        $service = new MovieService;
        $service->echooutput = false;
        $service->update([
            'imdbid' => '13622970',
            'title' => 'Moana 2',
            'year' => '2024',
        ]);

        Release::query()->insert([
            'id' => 3,
            'searchname' => 'Moana.2026.1080p.AMZN.WEB-DL.H.264-HDShare',
            'categories_id' => 2000,
            'imdbid' => null,
            'movieinfo_id' => null,
        ]);

        $this->setMovieServiceProperty($service, 'currentTitle', 'Moana');
        $this->setMovieServiceProperty($service, 'currentYear', '2026');

        $this->assertFalse($service->doMovieUpdate('tt13622970', 'Local DB', 3));
        $this->assertNull(Release::query()->whereKey(3)->value('imdbid'));
    }

    #[Test]
    public function it_does_not_assign_an_imdb_id_when_a_metadata_fetcher_rejects_the_candidate(): void
    {
        Cache::flush();

        $service = new class extends MovieService
        {
            public function updateMovieInfo(string $imdbId): bool
            {
                $this->candidateMismatch = true;

                return false;
            }
        };
        $service->echooutput = false;

        Release::query()->insert([
            'id' => 4,
            'searchname' => 'Moana.2026.1080p.AMZN.WEB-DL.H.264-HDShare',
            'categories_id' => 2000,
            'imdbid' => null,
            'movieinfo_id' => null,
        ]);

        $result = $service->doMovieUpdate('tt0137523', 'IMDb(scrape)', 4);

        $this->assertFalse($result);
        $this->assertNull(Release::query()->whereKey(4)->value('imdbid'));
    }

    private function invokeLocalIMDBSearch(MovieService $service): string|false
    {
        $method = new \ReflectionMethod($service, 'localIMDBSearch');

        return $method->invoke($service);
    }

    /**
     * @param  array<string, mixed>  $response
     */
    private function makeMovieServiceForTraktResponse(array $response): MovieService
    {
        $client = new class($response) extends TraktService
        {
            /**
             * @param  array<string, mixed>  $response
             */
            public function __construct(private array $response)
            {
                parent::__construct('test_trakt_key');
            }

            /**
             * @return array<string, mixed>|null
             */
            public function getMovieSummary(string $movie, string $extended = 'min'): ?array
            {
                return $this->response;
            }
        };

        $provider = new TraktProvider;
        $provider->client = $client;

        $service = new MovieService;
        $service->traktTv = $provider;
        $service->echooutput = false;

        $this->setMovieServiceProperty($service, 'traktcheck', 'test-key');

        return $service;
    }

    private function setMovieServiceProperty(MovieService $service, string $property, mixed $value): void
    {
        $reflectionProperty = new \ReflectionProperty($service, $property);
        $reflectionProperty->setValue($service, $value);
    }
}
