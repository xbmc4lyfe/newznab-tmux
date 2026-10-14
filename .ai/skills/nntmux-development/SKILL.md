---
name: nntmux-development
description: Apply NNTmux-specific contracts when changing application code, tests, tmux processing, search, monitoring integrations, or authentication.
---

# NNTmux development

Read repository `AGENTS.md` and matching `.ai/rules` first. Paths in code spans in these references are relative to the repository root.

Load the reference for the area being changed; load more than one when the task spans areas:

- [Architecture](references/architecture.md): service/pipeline layout, status probes, Grafana integration, passkeys, and key directories. Read the relevant subsection when working on these integrations.
- [Processing](references/processing.md): tmux modes, pane assignments, child output, batch limits, and parallelism settings.
- [Testing](references/testing.md): PHPUnit conventions, SQLite bootstrap settings, shared composer state, and existing harnesses.
- [Conventions](references/conventions.md): model/config contracts, API/RSS routing, signed Manticore columns, and admin content ordering.
- [Frontend](references/frontend.md): CSP-safe Alpine loading, Blade/Livewire boundaries, and Vite entry points.

These references describe project contracts; operational commands and tuning examples do not authorize production changes. Use the production operations skill for authorized runtime work and the performance validation skill when making ingestion/storage performance claims.
