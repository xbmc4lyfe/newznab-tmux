---
paths:
  - '**'
---

# Host commands

Use macOS or Linux for development, builds, tests, CI, runtime, and deployment. This project doesn't support Windows hosts or Windows Subsystem for Linux.

Run `cargo`, `just`, `python3` and `npm` on the host. Use Docker only for the test services (`just test-services`) and for the production stack lifecycle (`just docker ...`), or when the user asks for a containerized run.

- `just check` runs `cargo fmt --all --check`, `cargo clippy --workspace --all-targets --locked -- -D warnings` and `python3 rust/porting/check.py`.
- `just test` starts the test services in `rust/compose.test.yml`, then runs `cargo test --locked` with the `TEST_*` variables set. Pass cargo arguments through it: `just test -p nntmux-search`.
- Set `CARGO_BUILD_JOBS=4` (the recipes default to it). The Mac is shared by every worktree. Work on your own crate with `-p` and run the whole workspace only before a PR.
- Run `just frontend` after front end changes under `rust/frontend`.
- Check Docker Desktop or OrbStack on macOS, or the Docker service and socket access on Linux. Host tools run without Docker, except tests that need the test services.

## Validate behavior and label evidence

- Before changing behavior, capture a deterministic failing test through the real handler or role. Keep the success path and the relevant failure modes after the fix.
- Label local tests, reviewed branches, merged source, deployed services, and observed live behavior as separate evidence states. One doesn't prove another.
- For database or search performance changes, inspect actual query and index access plans where available, and validate result counts and lock and write behavior. Follow the [search-performance guide](../../docs/search-performance.md) for its workload matrix and index maintenance checks.
