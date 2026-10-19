---
paths:
  - '**'
---

# Host commands overrule Boost Sail

Use macOS or Linux for development, builds, tests, CI, runtime, and deployment. This project doesn't support Windows hosts or Windows Subsystem for Linux.

Run host commands such as `php artisan`, `./vendor/bin/pint`, and `npm run build`. These project rules override Boost's default requirement to use Sail for every command.

- Official Boost override: `.ai/guidelines/sail/core.blade.php` (wins on `boost:install` / `boost:update`).
- Host binaries for generated guidelines: `config/boost.php` → `executable_paths` defaults (`php`, `composer`, `npm`, `vendor/bin/`).
- Use Sail / `Justfile` only for Compose lifecycle or when the user asks for containerized runs.
- Check Docker Desktop or the configured Linux VM on macOS. Check the Docker service and socket access on Linux. Host tools can run while Docker is unavailable if their required services are reachable.

## Validate behavior and label evidence

- Before changing behavior, capture a deterministic failing test through the real handler; retain the success path and relevant failure modes after the fix.
- Label local tests, reviewed branches, merged source, deployed services, and observed live behavior as separate evidence states; one doesn't prove another.
- For database or search performance changes, inspect actual query/index access plans where available and validate result counts and lock/write behavior. Follow the [search-performance guide](../../docs/search-performance.md) for its workload matrix and index maintenance checks.
