---
name: nntmux-development
description: Apply NNTmux-specific contracts when changing application code, tests, worker roles, search, monitoring integrations, or authentication.
---

# NNTmux development

Read repository `AGENTS.md`, `rust/WORKSTREAMS.md` and matching `.ai/rules` first. Paths in code spans in these references are relative to the repository root.

Load the reference for the area being changed; load more than one when the task spans areas:

- [Architecture](references/architecture.md): crates, binaries, roles, data flow, and the metrics and Grafana setup.
- [Processing](references/processing.md): worker roles, the supervisor, drain and shutdown, and where tuning lives.
- [Testing](references/testing.md): `just test`, the shared test services, `TestDb`, fixtures and the ledger.
- [Conventions](references/conventions.md): configuration, the database schema, API routes, signed Manticore columns, and `nntmux-ctl`.
- [Frontend](references/frontend.md): the Vite build, CSP-safe Alpine, and the askama templates.

These references describe project contracts; operational commands and tuning examples do not authorize production changes. Use the production operations skill for authorized runtime work and the performance validation skill when making ingestion/storage performance claims.
