@AGENTS.md

## Claude routing

These model rules apply to Claude subagents. Keep the human-selected main-session model unchanged. Delegate bounded independent work when useful; do small coupled work directly. Read `AGENTS.md` and `.ai/rules/index.md` before editing. Follow their Git review gates, macOS/Linux platform policy, and performance benchmark requirements. The routing table in `AGENTS.md` applies only to Codex delegation. Required reviews may use Antigravity or Codex.

| Delegated task | Model | Effort |
| --- | --- | --- |
| Read-only file/symbol lookup, documentation checks, log summarization | `haiku-5.5` | `high` |
| Bounded implementation or focused review with clear contracts | `sonnet-5.5` | `medium` |
| Cross-service debugging, transaction or concurrency reasoning | `sonnet-5.5` | `high` |
| Ambiguous architecture, security boundaries, release/storage safety | `opus-5.5` | `high` |

- Set both `model` and `effort` explicitly when spawning a non-fork subagent, using a compact, self-contained brief. Command-line children use `--model` and `--effort`. Choose the tool's built-in subagent type suited to the task; no project agent definition is required.
- Choose by uncertainty and failure impact, not diff length. Escalate with a concrete reason after a failed approach or an unresolved invariant; reserve higher supported effort for demonstrated difficulty.
- Give the agent goal, paths/skills, constraints, owned files, validation, and required evidence. Parallelize independent reads; writers need exclusive files or isolated worktrees. Reviewers receive raw artifacts and acceptance contracts.
- Check model availability and resolved effort. `CLAUDE_CODE_EFFORT_LEVEL` can override effort; forced subagent-model configuration can override models. Report mismatches and use a supported route without changing global settings.
- If the native spawn tool lacks per-invocation effort (including Claude Code before v2.1.292), use an authorized command-line process with both flags, or report that the requested route can't be applied. Don't silently claim the requested effort was used. Follow the shared Git workflow for commits, branch pushes, pull requests, and merges. Production changes need separate authorization.
