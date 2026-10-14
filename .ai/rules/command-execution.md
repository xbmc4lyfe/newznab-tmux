---
paths:
  - '**'
---

# Host commands overrule Boost Sail

NNTmux agents must prefer WSL-host commands (`php artisan`, `./vendor/bin/pint`, `npm run build`, etc.) over Laravel Boost's built-in Sail rule that says every command must go through Sail.

- Official Boost override: `.ai/guidelines/sail/core.blade.php` (wins on `boost:install` / `boost:update`).
- Host binaries for generated guidelines: `config/boost.php` → `executable_paths` defaults (`php`, `composer`, `npm`, `vendor/bin/`).
- Use Sail / `Makefile` only for Compose lifecycle or when the user asks for containerized runs.
- On WSL2, Docker often comes from Windows Docker Desktop; missing `docker` usually means Desktop/WSL integration, not a missing apt package.

## Validate behavior and label evidence

- Before changing behavior, capture a deterministic failing test through the real handler; retain the success path and relevant failure modes after the fix.
- Label local tests, reviewed branches, merged source, deployed services, and observed live behavior as separate evidence states; one does not prove another.
- For database or search performance changes, inspect actual query/index access plans where available and validate result counts and lock/write behavior. Follow the [search-performance guide](../../docs/search-performance.md) for its workload matrix and index maintenance checks.
