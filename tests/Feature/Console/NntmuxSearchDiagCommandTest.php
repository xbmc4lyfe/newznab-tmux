<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

final class NntmuxSearchDiagCommandTest extends SearchConsoleCommandTestCase
{
    public function test_search_diag_exits_failure_when_driver_is_not_manticore(): void
    {
        config(['search.default' => 'elasticsearch']);

        $this->artisan('nntmux:search-diag', ['ids' => ['1']])
            ->assertExitCode(1);
    }
}
