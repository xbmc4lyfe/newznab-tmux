<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Facades\Search;
use App\Services\Releases\ReleaseBrowseService;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class ReleaseBrowseSearchCacheTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['cache.default' => 'array']);
        $this->app['cache']->setDefaultDriver('array');
        Cache::flush();
    }

    #[Test]
    public function it_reuses_the_search_result_when_excluded_categories_have_equivalent_ordering(): void
    {
        Search::shouldReceive('isAvailable')->twice()->andReturnTrue();
        Search::shouldReceive('searchReleasesFiltered')
            ->once()
            ->withArgs(function (array $criteria, int $limit, int $offset): bool {
                return $criteria['excluded_category_ids'] === [2030, 2020]
                    && $limit === 100
                    && $offset === 0;
            })
            ->andReturn($this->searchResult(42));

        $service = new ReleaseBrowseService;

        $first = $service->getBrowseRangeForApi(1, [], 0, 100, 'posted_desc', -1, [2030, 2020]);
        $second = $service->getBrowseRangeForApi(1, [], 0, 100, 'posted_desc', -1, [2020, 2030]);

        $this->assertSame(42, $first[0]->id);
        $this->assertSame(42, $second[0]->id);
    }

    #[Test]
    public function it_keeps_the_first_page_independent_from_the_paginated_block(): void
    {
        Search::shouldReceive('isAvailable')->times(3)->andReturnTrue();
        Search::shouldReceive('searchReleasesFiltered')
            ->once()
            ->withArgs(fn (array $criteria, int $limit, int $offset): bool => $limit === 100 && $offset === 0)
            ->andReturn($this->searchResult(42));
        Search::shouldReceive('searchReleasesFiltered')
            ->once()
            ->withArgs(fn (array $criteria, int $limit, int $offset): bool => $limit === 500 && $offset === 100)
            ->andReturn($this->searchResultRange(101, 500, 10_000));

        $service = new ReleaseBrowseService;

        $firstPage = $service->getBrowseRangeForApi(1, [], 0, 100, 'posted_desc');
        $secondPage = $service->getBrowseRangeForApi(2, [], 100, 100, 'posted_desc');
        $thirdPage = $service->getBrowseRangeForApi(3, [], 200, 100, 'posted_desc');

        $this->assertSame(42, $firstPage[0]->id);
        $this->assertSame(101, $secondPage[0]->id);
        $this->assertSame(200, $secondPage[99]->id);
        $this->assertSame(201, $thirdPage[0]->id);
        $this->assertSame(300, $thirdPage[99]->id);
    }

    #[Test]
    public function it_reuses_a_deep_search_block_for_adjacent_pages(): void
    {
        Search::shouldReceive('isAvailable')->twice()->andReturnTrue();
        Search::shouldReceive('searchReleasesFiltered')
            ->once()
            ->withArgs(fn (array $criteria, int $limit, int $offset): bool => $limit === 500 && $offset === 2600)
            ->andReturn($this->searchResultRange(2601, 500, 10_000));

        $service = new ReleaseBrowseService;

        $firstPage = $service->getBrowseRangeForApi(27, [], 2600, 100, 'posted_desc');
        $secondPage = $service->getBrowseRangeForApi(28, [], 2700, 100, 'posted_desc');

        $this->assertCount(100, $firstPage);
        $this->assertSame(2601, $firstPage[0]->id);
        $this->assertSame(2700, $firstPage[99]->id);
        $this->assertCount(100, $secondPage);
        $this->assertSame(2701, $secondPage[0]->id);
        $this->assertSame(2800, $secondPage[99]->id);
    }

    #[Test]
    public function it_uses_an_exact_search_when_a_request_crosses_a_block_boundary(): void
    {
        Search::shouldReceive('isAvailable')->once()->andReturnTrue();
        Search::shouldReceive('searchReleasesFiltered')
            ->once()
            ->withArgs(fn (array $criteria, int $limit, int $offset): bool => $limit === 100 && $offset === 3050)
            ->andReturn($this->searchResultRange(3051, 100, 10_000));

        $service = new ReleaseBrowseService;

        $page = $service->getBrowseRangeForApi(31, [], 3050, 100, 'posted_desc');

        $this->assertCount(100, $page);
        $this->assertSame(3051, $page[0]->id);
        $this->assertSame(3150, $page[99]->id);
    }

    #[Test]
    public function it_counts_browse_rows_through_the_search_index_without_the_pager_cap(): void
    {
        config(['nntmux.max_pager_results' => 125000]);

        Search::shouldReceive('isAvailable')->once()->andReturnTrue();
        Search::shouldReceive('searchReleasesFiltered')
            ->once()
            ->withArgs(fn (array $criteria, int $limit): bool => $limit === 1
                && $criteria['phrases'] === null
                && $criteria['max_age_days'] === 7
                && $criteria['excluded_category_ids'] === [2030]
                && $criteria['groups_id'] === null)
            ->andReturn(['ids' => [1], 'total' => 2_697_382, 'total_exact' => true, 'fuzzy' => false, 'available' => true]);

        $service = new ReleaseBrowseService;

        $this->assertSame(2_697_382, $service->getBrowseCount([], 7, [2030], -1));
        // A repeat call is served from the cache without a second index query.
        $this->assertSame(2_697_382, $service->getBrowseCount([], 7, [2030], -1));
    }

    #[Test]
    public function it_falls_back_to_the_database_count_when_the_search_index_is_unavailable(): void
    {
        Search::shouldReceive('isAvailable')->once()->andReturnFalse();
        Search::shouldReceive('searchReleasesFiltered')->never();

        $this->assertSame(0, (new ReleaseBrowseService)->getBrowseCount([], -1, [], -1));
    }

    #[Test]
    public function it_falls_back_to_the_database_count_when_the_index_total_is_estimated_or_empty(): void
    {
        Search::shouldReceive('isAvailable')->times(3)->andReturnTrue();
        Search::shouldReceive('searchReleasesFiltered')
            ->once()
            ->andReturn(['ids' => [1], 'total' => 5_000, 'fuzzy' => false, 'available' => true]);
        Search::shouldReceive('searchReleasesFiltered')
            ->once()
            ->andReturn(['ids' => [1], 'total' => 5_000, 'total_exact' => false, 'fuzzy' => false, 'available' => true]);
        Search::shouldReceive('searchReleasesFiltered')
            ->once()
            ->andReturn(['ids' => [], 'total' => 0, 'total_exact' => true, 'fuzzy' => false, 'available' => true]);

        $service = new ReleaseBrowseService;

        $this->assertSame(0, $service->getBrowseCount([], -1, [], -1));
        $this->assertSame(0, $service->getBrowseCount([], 30, [], -1));
        $this->assertSame(0, $service->getBrowseCount([], 60, [], -1));
    }

    #[Test]
    public function it_falls_back_to_the_database_count_when_the_index_query_fails(): void
    {
        Search::shouldReceive('isAvailable')->once()->andReturnTrue();
        Search::shouldReceive('searchReleasesFiltered')
            ->once()
            ->andReturn(['ids' => [], 'total' => 0, 'fuzzy' => false, 'available' => false]);

        $this->assertSame(0, (new ReleaseBrowseService)->getBrowseCount([], -1, [], -1));
    }

    /**
     * @return array{ids: list<int>, documents: list<array<string, mixed>>, total: int}
     */
    private function searchResult(int $id): array
    {
        return $this->searchResultRange($id, 1);
    }

    /**
     * @return array{ids: list<int>, documents: list<array<string, mixed>>, total: int}
     */
    private function searchResultRange(int $firstId, int $count, ?int $total = null): array
    {
        $ids = range($firstId, $firstId + $count - 1);

        return [
            'ids' => $ids,
            'documents' => array_map(static fn (int $id): array => [
                'id' => $id,
                'searchname' => 'Example release '.$id,
                'postdate_ts' => 1_700_000_000 + $id,
                'adddate_ts' => 1_700_000_100 + $id,
            ], $ids),
            'total' => $total ?? count($ids),
        ];
    }
}
