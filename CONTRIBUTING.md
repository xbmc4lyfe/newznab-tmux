# Contribute to this fork

Use macOS or Linux for development, builds, tests, CI, runtime, and deployment. Read [AGENTS.md](AGENTS.md) and [.ai/rules/index.md](.ai/rules/index.md) before editing.

Create a feature branch and target `main` in `xbmc4lyfe/newznab-tmux`. Follow the [Git workflow](.ai/rules/git-workflow.md): obtain a clean independent local Codex review before opening the PR, then obtain Codex approval for its final head and pass required checks before a squash merge.

Try Rust first for new logic and attempt Rust replacements when fixing or upgrading existing logic. Follow the [Rust benchmark requirements](.ai/rules/rust-performance.md). Preserve correctness and require at least a 1.5× gain on common paths and uncommon p99 workloads before adopting Rust.

Changes submitted to `NNTmux/newznab-tmux` follow that repository's contribution rules. Synchronizing upstream into this fork uses a merge commit and the review gates in the Git workflow.
