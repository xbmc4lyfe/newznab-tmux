# Project rules index

Path-scoped and standing agent rules for NNTmux (Rust-native). Hand-written entries live here.

| Glob | File | Summary |
|------|------|---------|
| `**` | [command-execution.md](./command-execution.md) | Host commands (`just`, `cargo`), build load, behavior reproduction, evidence labels |
| `**` | [git-workflow.md](./git-workflow.md) | Task branches, local review (Antigravity or Codex), PR review, and squash merges |
| `rust/**` | [rust-performance.md](./rust-performance.md) | Benchmark evidence for hot paths; no regressions on common or p99 paths |

The rewrite's own rules live in [rust/WORKSTREAMS.md](../../rust/WORKSTREAMS.md) and the cutover plan in [rust/CUTOVER.md](../../rust/CUTOVER.md).

Before editing, also run `rg -n -i 'keyword' .ai/rules` for related notes.
