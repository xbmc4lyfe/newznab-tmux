<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class ReleaseResultsComponentTest extends TestCase
{
    public function test_browse_and_search_use_shared_release_results_component(): void
    {
        $browse = (string) file_get_contents(__DIR__.'/../../resources/views/browse/index.blade.php');
        $search = (string) file_get_contents(__DIR__.'/../../resources/views/search/index.blade.php');
        $panel = (string) file_get_contents(__DIR__.'/../../resources/views/components/release-results-panel.blade.php');

        $this->assertStringContainsString('<x-release-results-panel :results="$results"', $browse);
        $this->assertStringContainsString('<x-release-results-panel :results="$results"', $search);
        $this->assertStringContainsString('<x-release-results :results="$results"', $panel);
        $this->assertStringNotContainsString('@foreach($results as $result)', $browse);
        $this->assertStringNotContainsString('@foreach($results as $result)', $search);
    }

    public function test_shared_release_results_panel_keeps_bulk_actions_and_toolbar_slots(): void
    {
        $panel = (string) file_get_contents(__DIR__.'/../../resources/views/components/release-results-panel.blade.php');

        $this->assertStringContainsString('nzb_multi_operations_form', $panel);
        $this->assertStringContainsString('nzb_multi_operations_download', $panel);
        $this->assertStringContainsString('nzb_multi_operations_cart', $panel);
        $this->assertStringContainsString('nzb_multi_operations_delete', $panel);
        $this->assertStringContainsString('$beforeActions', $panel);
        $this->assertStringContainsString('$toolbarRight', $panel);
        $this->assertStringContainsString('$summary', $panel);
    }

    public function test_shared_release_results_component_keeps_expected_release_actions(): void
    {
        $component = (string) file_get_contents(__DIR__.'/../../resources/views/components/release-results.blade.php');

        $this->assertStringContainsString('download-nzb', $component);
        $this->assertStringContainsString('add-to-cart', $component);
        $this->assertStringContainsString('filelist-badge', $component);
        $this->assertStringContainsString('nfo-badge', $component);
        $this->assertStringContainsString('preview-badge', $component);
        $this->assertStringContainsString('mediainfo-badge', $component);
        $this->assertStringContainsString('<x-report-button', $component);
    }
}
