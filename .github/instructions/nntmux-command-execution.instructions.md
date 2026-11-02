---
applyTo: "**"
description: Use macOS or Linux host tools for development, builds, and tests
---

# Command execution

This project is all Rust. Run builds and tests on the host. See `.ai/rules/command-execution.md`.

## Default

Use macOS or Linux for development, builds, tests, CI, runtime, and deployment. Run `cargo`, `just`, `python3` and `npm` on the host:

```bash
just check                       # cargo fmt --check, clippy -D warnings, rust/porting/check.py
just test -p nntmux-search       # one crate; `just test` runs the workspace
just frontend                    # after changes under rust/frontend
```

Set `CARGO_BUILD_JOBS=4` (the recipes default to it): the Mac is shared by every worktree.

## Docker only when needed

`just test` starts the test services in `rust/compose.test.yml` for you. Use `just docker ...` for stack lifecycle, or when the user explicitly asks for containerized commands. Feature sessions never run `just deploy`.

## Docker diagnostics

Check Docker Desktop or OrbStack on macOS. Check the Docker service and socket access on Linux. Host tools run without Docker, except tests that need the test services.
