<?php

namespace Tests\Feature;

use Tests\TestCase;

class AdminDashboardReportedReleasesTest extends TestCase
{
    /**
     * Test that the dashboard blade template contains reported releases widget markup.
     */
    public function test_dashboard_blade_contains_reported_releases_widget(): void
    {
        $bladePath = resource_path('views/admin/dashboard.blade.php');

        $this->assertFileExists($bladePath);

        $content = file_get_contents($bladePath);

        // Check that the blade file contains the reported releases widget
        $this->assertStringContainsString('Reported Releases', $content);
        $this->assertStringContainsString("stats['reported']", $content);
        $this->assertStringContainsString('/admin/release-reports', $content);
        $this->assertStringContainsString('View reports', $content);
    }

    /**
     * Test that the dashboard snapshot exposes the reported-releases count.
     *
     * The "stats" payload is built by AdminDashboardSnapshotService and
     * surfaced to the Blade view (and to the auto-refresh JSON endpoint)
     * through AdminPageController, so the assertions live on the service.
     */
    public function test_controller_stats_method_has_reported_key(): void
    {
        $servicePath = app_path('Services/AdminDashboardSnapshotService.php');

        $this->assertFileExists($servicePath);

        $content = (string) file_get_contents($servicePath);

        $this->assertStringContainsString("'reported'", $content);
        $this->assertStringContainsString('ReleaseReport::', $content);

        $controllerPath = app_path('Http/Controllers/Admin/AdminPageController.php');
        $controllerContent = (string) file_get_contents($controllerPath);

        // Controller still surfaces the snapshot stats payload to the view
        // and the JSON endpoint used by the auto-refresh JS.
        $this->assertStringContainsString("'stats' => \$payload['stats']", $controllerContent);
    }
}
