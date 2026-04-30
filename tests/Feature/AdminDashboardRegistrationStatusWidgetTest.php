<?php

namespace Tests\Feature;

use Tests\TestCase;

class AdminDashboardRegistrationStatusWidgetTest extends TestCase
{
    public function test_dashboard_blade_contains_registration_status_widget(): void
    {
        $bladePath = resource_path('views/admin/dashboard.blade.php');

        $this->assertFileExists($bladePath);

        $content = file_get_contents($bladePath);

        $this->assertStringContainsString('Site Registration Status', $content);
        $this->assertStringContainsString("route('admin.registrations.index')", $content);
        $this->assertStringContainsString("registrationStatus['effective_status_label']", $content);
        $this->assertStringContainsString("registrationStatus['manual_status_label']", $content);
        $this->assertStringContainsString('$nextRegistrationPeriod', $content);
        $this->assertStringContainsString('Manage Registrations', $content);
    }

    public function test_dashboard_controller_provides_registration_widget_data(): void
    {
        // The dashboard view receives `registrationStatus` / `nextRegistrationPeriod`
        // built by AdminDashboardSnapshotService, and the auto-refresh JSON
        // endpoint serialises the same payload for the client.
        $snapshotPath = app_path('Services/AdminDashboardSnapshotService.php');
        $controllerPath = app_path('Http/Controllers/Admin/AdminPageController.php');

        $this->assertFileExists($snapshotPath);
        $this->assertFileExists($controllerPath);

        $snapshotContent = (string) file_get_contents($snapshotPath);
        $controllerContent = (string) file_get_contents($controllerPath);

        // Snapshot builds the registration data used by the widget.
        $this->assertStringContainsString('RegistrationStatusService', $snapshotContent);
        $this->assertStringContainsString("'registrationStatus' => \$registrationStatus", $snapshotContent);
        $this->assertStringContainsString("'nextRegistrationPeriod' => \$nextRegistrationPeriod", $snapshotContent);
        $this->assertStringContainsString('getNextUpcomingPeriod', $snapshotContent);

        // Controller passes the snapshot fields through to the Blade view
        // and the JSON endpoint consumed by the auto-refresh JS.
        $this->assertStringContainsString("'registrationStatus' => \$payload['registrationStatus']", $controllerContent);
        $this->assertStringContainsString("'nextRegistrationPeriod' => \$payload['nextRegistrationPeriod']", $controllerContent);
        $this->assertStringContainsString("'registrationStatus' => \$registrationPayload", $controllerContent);
    }
}
