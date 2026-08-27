<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class LayoutShellMarkupTest extends TestCase
{
    public function test_user_and_admin_layouts_expose_accessible_shell_landmarks(): void
    {
        foreach (['main', 'admin'] as $layout) {
            $markup = $this->view("layouts/{$layout}.blade.php");

            $this->assertStringContainsString('class="skip-link"', $markup);
            $this->assertStringContainsString('class="layout-shell', $markup);
            $this->assertStringContainsString('class="layout-sidebar', $markup);
            $this->assertStringContainsString('class="layout-topbar', $markup);
            $this->assertStringContainsString('id="main-content"', $markup);
            $this->assertStringContainsString('id="mobile-sidebar-backdrop"', $markup);
            $this->assertStringContainsString('aria-controls="sidebar"', $markup);
            $this->assertStringContainsString('aria-expanded="false"', $markup);
        }

        $this->assertStringNotContainsString('assets/images/logo.svg', $this->view('layouts/main.blade.php'));
    }

    public function test_mobile_sidebar_script_supports_dismissal_and_focus_state(): void
    {
        $script = $this->resource('js/alpine/components/mobile-nav.js');

        $this->assertStringContainsString("event.key === 'Escape'", $script);
        $this->assertStringContainsString("mobileSidebarBackdrop?.addEventListener('click'", $script);
        $this->assertStringContainsString("mobileSidebarToggle.setAttribute('aria-expanded'", $script);
        $this->assertStringContainsString('mobileSidebarClose?.focus()', $script);
    }

    public function test_shared_shell_styles_include_focus_and_reduced_motion_support(): void
    {
        $stylesheet = $this->resource('css/app.css');

        $this->assertStringContainsString(':focus-visible', $stylesheet);
        $this->assertStringContainsString('@media (prefers-reduced-motion: reduce)', $stylesheet);
        $this->assertStringContainsString('.layout-flash--success', $stylesheet);
        $this->assertStringContainsString('.admin-page-header__icon', $stylesheet);
    }

    public function test_shared_icon_only_controls_have_accessible_names(): void
    {
        $this->assertStringContainsString('aria-label="Search releases"', $this->view('partials/header-menu.blade.php'));
        $this->assertStringContainsString('aria-label="Close confirmation"', $this->view('partials/confirmation-modal.blade.php'));
        $this->assertStringContainsString('aria-label="Dismiss notification"', $this->view('partials/toast-notifications.blade.php'));
    }

    private function view(string $path): string
    {
        return $this->resource("views/{$path}");
    }

    private function resource(string $path): string
    {
        return (string) file_get_contents(__DIR__."/../../resources/{$path}");
    }
}
