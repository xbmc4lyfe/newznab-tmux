<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class MoviesPageMarkupTest extends TestCase
{
    public function test_movie_page_exposes_a_semantic_heading_and_accessible_filters(): void
    {
        $markup = $this->resource('views/movies/index.blade.php');

        $this->assertStringContainsString('<h1 id="movies-heading"', $markup);
        $this->assertStringContainsString('aria-label="Movie filters"', $markup);
        $this->assertStringContainsString('<label for="title"', $markup);
        $this->assertStringContainsString('<label for="genre"', $markup);
        $this->assertStringContainsString('<label for="year"', $markup);
        $this->assertStringContainsString('<label for="rating"', $markup);
        $this->assertStringContainsString('absolute inset-y-0 left-0 flex w-10 items-center justify-center', $markup);
        $this->assertStringContainsString('class="movie-filter-control pl-10"', $markup);
        $this->assertStringContainsString("route('trending-movies')", $markup);
        $this->assertStringContainsString("route('Movies')", $markup);
        $this->assertStringContainsString(':aria-label="layout === 1', $markup);
    }

    public function test_movie_hero_matches_the_compact_shared_hero_spacing(): void
    {
        $markup = $this->resource('views/movies/index.blade.php');

        $this->assertStringContainsString('movies-hero relative overflow-hidden rounded-2xl px-5 py-4', $markup);
        $this->assertStringContainsString('sm:px-7 sm:py-5', $markup);
        $this->assertStringContainsString('relative z-10 flex flex-col gap-4', $markup);
        $this->assertStringContainsString('aria-label="Breadcrumb" class="mb-2', $markup);
        $this->assertStringContainsString('class="mt-2 max-w-2xl text-sm leading-5', $markup);
        $this->assertStringContainsString('movies-hero__meta flex min-w-0 flex-wrap', $markup);
        $this->assertStringNotContainsString('mt-3 flex flex-wrap gap-2 text-sm', $markup);
        $this->assertStringNotContainsString('sm:py-8', $markup);
    }

    public function test_movie_cards_reserve_poster_space_and_reuse_release_rows(): void
    {
        $card = $this->resource('views/movies/partials/movie-card.blade.php');

        $this->assertStringContainsString('<article class="movie-card', $card);
        $this->assertStringContainsString('width="320"', $card);
        $this->assertStringContainsString('height="480"', $card);
        $this->assertStringContainsString('loading="lazy"', $card);
        $this->assertStringContainsString('decoding="async"', $card);
        $this->assertStringContainsString("@include('movies.partials.release-item'", $card);
        $this->assertStringContainsString('rel="noopener noreferrer"', $card);
        $this->assertStringContainsString('(opens in a new tab)', $card);
    }

    public function test_movie_styles_cover_grid_list_dark_and_reduced_motion_states(): void
    {
        $stylesheet = $this->resource('css/csp-safe.css');

        $this->assertStringContainsString('.movies-grid[data-layout="2"]', $stylesheet);
        $this->assertStringContainsString('.movies-grid[data-layout="1"] .release-card-container', $stylesheet);
        $this->assertStringContainsString('.dark .movie-filter-control', $stylesheet);
        $this->assertStringContainsString('@media (min-width: 640px)', $stylesheet);
        $this->assertStringContainsString('@media (prefers-reduced-motion: reduce)', $stylesheet);
    }

    public function test_layout_toggle_retains_persistence_and_clear_state_labels(): void
    {
        $script = $this->resource('js/alpine/components/movies-page.js');

        $this->assertStringContainsString("'Comfortable list'", $script);
        $this->assertStringContainsString("'Compact grid'", $script);
        $this->assertStringContainsString("fetch('/movies/update-layout'", $script);
        $this->assertStringContainsString('body: JSON.stringify({ layout: this.layout })', $script);
    }

    private function resource(string $path): string
    {
        return (string) file_get_contents(__DIR__."/../../resources/{$path}");
    }
}
