<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class CoverBrowseComponentsTest extends TestCase
{
    public function test_cover_browse_pages_use_shared_toolbar_component(): void
    {
        $books = (string) file_get_contents(__DIR__.'/../../resources/views/books/index.blade.php');
        $music = (string) file_get_contents(__DIR__.'/../../resources/views/music/index.blade.php');
        $games = (string) file_get_contents(__DIR__.'/../../resources/views/games/index.blade.php');

        $this->assertStringContainsString('<x-cover-results-toolbar', $books);
        $this->assertStringContainsString('<x-cover-results-toolbar', $music);
        $this->assertStringContainsString('<x-cover-results-toolbar', $games);
    }

    public function test_music_and_games_covers_resolve_the_stored_image_by_metadata_id(): void
    {
        // Covers are saved as covers/{type}/{id}.webp|jpg; `cover` is only a 0/1 "has cover" flag.
        $music = (string) file_get_contents(__DIR__.'/../../resources/views/music/index.blade.php');
        $games = (string) file_get_contents(__DIR__.'/../../resources/views/games/index.blade.php');

        $this->assertStringContainsString("getImageAssetUrl('music', (string) \$result->id", $music);
        $this->assertStringContainsString("getImageAssetUrl('games', (string) \$result->id", $games);
        $this->assertStringNotContainsString("url('/covers/music/' . \$result->cover)", $music);
        $this->assertStringNotContainsString("url('/covers/games/' . \$result->cover)", $games);
    }

    public function test_cover_browse_release_cards_use_shared_release_list_component(): void
    {
        $console = (string) file_get_contents(__DIR__.'/../../resources/views/console/index.blade.php');
        $music = (string) file_get_contents(__DIR__.'/../../resources/views/music/index.blade.php');
        $games = (string) file_get_contents(__DIR__.'/../../resources/views/games/index.blade.php');
        $component = (string) file_get_contents(__DIR__.'/../../resources/views/components/cover-release-list.blade.php');

        $this->assertStringContainsString('<x-cover-release-list', $console);
        $this->assertStringContainsString('<x-cover-release-list', $music);
        $this->assertStringContainsString('<x-cover-release-list', $games);
        $this->assertStringNotContainsString('$displayReleases = array_slice', $console);
        $this->assertStringNotContainsString('$displayReleases = array_slice', $music);
        $this->assertStringNotContainsString('$displayReleases = array_slice', $games);

        $this->assertStringContainsString('nfo-badge', $component);
        $this->assertStringContainsString('add-to-cart', $component);
        $this->assertStringContainsString('chkRelease', $component);
        $this->assertStringContainsString('Available releases', $component);
    }
}
