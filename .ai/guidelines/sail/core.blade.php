@php
/** @var \Laravel\Boost\Install\GuidelineAssist $assist */
@endphp
# Laravel Sail (project override)

> **This guideline OVERRULES Boost's built-in Sail rules.** Do not require `{{ $assist->sailBinaryPath() }}` for day-to-day PHP, Artisan, Composer, Node, Pint, PHPStan, or tests.

## Default: use macOS or Linux host tools

Use macOS or Linux for development, builds, tests, CI, runtime, and deployment. Run host commands as described in `AGENTS.md`:

- Artisan: `{{ $assist->artisanCommand('[command]') }}` (e.g. `php artisan test --compact --filter=TestName`)
- Composer: `{{ $assist->composerCommand('install') }}`
- Node/npm: `{{ $assist->nodePackageManagerCommand('run build') }}`
- Vendor bins: `{{ $assist->binCommand('pint --dirty') }}`, `{{ $assist->binCommand('phpstan analyse --memory-limit=2G') }}`

Do **not** prefix every command with Sail. Ignore any conflicting Boost text that says you MUST execute all commands through Sail.

## When Sail / Docker is appropriate

Use Sail or `Justfile` recipes only when:

- Starting/stopping the stack (`just up` / `{{ $assist->sailBinaryPath() }} up -d`, `just down` / `{{ $assist->sailBinaryPath() }} stop`)
- The user explicitly asks for containerized commands
- A task needs services only available inside Compose (e.g. aligning container UIDs via `just fix-permissions`)

## Docker diagnostics

- Check Docker Desktop or the configured Linux VM on macOS when the daemon is unavailable.
- Check the Docker service and socket access on Linux.
- Host PHP, Artisan, and tests can run without Docker if their required services are reachable.
