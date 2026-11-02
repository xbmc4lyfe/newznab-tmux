# Contribute to this fork

Use macOS or Linux for development, builds, tests, CI, runtime, and deployment. Read [AGENTS.md](AGENTS.md), [.ai/rules/index.md](.ai/rules/index.md) and [rust/WORKSTREAMS.md](rust/WORKSTREAMS.md) before editing.

Create a feature branch from `xbmc4lyfe/newznab-tmux` and target `rust-native`, the default branch (`master` holds only the PHP history). Follow the [Git workflow](.ai/rules/git-workflow.md): obtain a clean independent local review before opening the PR, then obtain review approval for its final head and pass required checks before a squash merge.

Before you open a PR, run:

```bash
just check    # cargo fmt --all --check, clippy -D warnings, rust/porting/check.py
just test     # starts the test services, then cargo test --locked
```

While working, test your own crate with `just test -p <crate>`. Set `CARGO_BUILD_JOBS=4` on a shared machine; the recipes default to it. Run `just frontend` after front end changes.

Changes on hot paths need benchmark evidence and must not regress common or p99 paths. Follow the [performance requirements](.ai/rules/rust-performance.md).

Changes submitted to `NNTmux/newznab-tmux` follow that repository's contribution rules. Synchronizing upstream into this fork uses a merge commit and the review gates in the Git workflow.
