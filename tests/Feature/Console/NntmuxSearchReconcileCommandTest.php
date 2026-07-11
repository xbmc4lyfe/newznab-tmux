<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

final class NntmuxSearchReconcileCommandTest extends SearchConsoleCommandTestCase
{
    public function test_search_reconcile_exits_failure_when_driver_is_not_manticore(): void
    {
        config(['search.default' => 'elasticsearch']);

        $this->artisan('nntmux:search-reconcile', ['--dry-run' => true])
            ->assertExitCode(1);
    }
}
