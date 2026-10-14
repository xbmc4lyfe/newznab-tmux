# Codex routing

These model rules apply to Codex subagents only; Claude uses `CLAUDE.md`. Keep the human-selected main-session model unchanged. Delegate bounded independent work when useful; do small coupled work directly.

| Delegated task | Model | Effort |
| --- | --- | --- |
| File/symbol inventory, source lookup, log summarization, mechanical edits | `gpt-6-luna` | `high` |
| Bounded Laravel implementation or focused correctness review | `gpt-6.1-sol` | `medium` |
| Cross-service debugging, transaction reasoning, concurrency fixes | `gpt-6.1-sol` | `high` |
| Ambiguous architecture, security boundaries, release/storage safety review | `gpt-6-astra` | `high` |

- Choose complexity by uncertainty, interacting state, and failure impact; a short diff can require the strongest route.
- Set both `model` and `reasoning_effort` explicitly on native `spawn_agent`; use `fork_turns="none"` with a self-contained brief when overrides require it. Command-line children use `--model` and `-c model_reasoning_effort=...`.
- Include goal, relevant paths/skills, constraints, file ownership, validation, and completion evidence. Give independent reviewers the contract and diff without the implementer's conclusions.
- Escalate when evidence shows the task exceeds its route; give a concrete reason. Reserve `xhigh`/`max` for unresolved difficult reasoning. Check advertised model/effort support; report unavailable overrides rather than silently inheriting or bypassing permissions.
- Parallelize independent reads; give each writer exclusive files or an isolated worktree. Keep the source/experiment/deployment ownership rules below.

# Git workflow and Codex review

A human prompt or goal authorizes agents to commit task changes, push a feature branch, open a PR, and squash-merge it into `master` in `xbmc4lyfe/newznab-tmux` after all review gates pass. Follow [.ai/rules/git-workflow.md](.ai/rules/git-workflow.md). This permission doesn't authorize deployment, production changes, or dependency changes.

- Before opening a PR, obtain an independent local Codex review with no findings. Use a Codex subagent when a slot is available, or a separate Codex command-line process when all Codex subagent slots have active tasks or the current harness isn't Codex.
- After opening the PR, request `@codex review`. Address and reply to every finding, resolve each review thread, and request another review after fixes or an explanation that a finding doesn't apply.
- Merge only after Codex gives an explicit approval or go-ahead for the current PR head and required checks pass. Every change after a review requires a fresh review with no findings.
- If the GitHub Codex app reports exhausted review credits, use a fresh independent local review of the current PR head with `gpt-6.1-sol` or a stronger model, `high` effort, and normal speed. Record the review and commit hash in the PR. Use a separate process if a subagent can't select these settings.
- Feature pull requests target this fork's `master`. Upstream synchronization with `NNTmux/newznab-tmux` uses a merge commit and requires a separate synchronization request.

## Code review rules

- Review the complete PR diff against its destination branch. Report every actionable finding and identify the reviewed head commit hash. An exit code alone isn't approval.
- Check the review gates, macOS/Linux platform policy, and [Rust performance requirements](.ai/rules/rust-performance.md) when they apply. Approve only after the current revision has no unresolved findings.

# NNTmux agent guide

Laravel 13 Usenet indexer. Use this file for repo-wide defaults; load a project skill below when its domain applies.

## Before planning or editing

- Read [.ai/rules/index.md](.ai/rules/index.md), every rule matching the target paths, and search `.ai/rules` for task keywords.
- Record durable shared rules with Boost's `record-rule`; don't substitute personal memory.
- Follow existing sibling conventions and reuse existing components. Keep dependencies and new base directories subject to user approval.
- Create documentation only when requested. Keep replies concise.

## Command execution precedence

- Use macOS or Linux for development, builds, tests, CI, runtime, and deployment. This project doesn't support Windows hosts or Windows Subsystem for Linux. Run host tools by default; use Sail/`make` for Compose lifecycle or an explicit containerized request. See [.ai/rules/command-execution.md](.ai/rules/command-execution.md).
- Examples: `php artisan test --compact --filter=TestName`, `./vendor/bin/pint --dirty`, `./vendor/bin/phpstan analyse --memory-limit=2G`, `npm run build` after frontend edits.

## Rust implementation and performance

Try Rust first for new app logic. When fixing or upgrading code in another language, attempt a Rust replacement for the affected logic. Compare it with the existing implementation or an implementation native to that codebase. Adopt Rust only after correctness checks and reproducible benchmarks show at least a 1.5× speedup on common paths and uncommon p99 workloads; aim for 2–3×. Include integration overhead. If Rust doesn't meet the threshold, retain the native implementation and document the evidence. Follow [.ai/rules/rust-performance.md](.ai/rules/rust-performance.md).

## Changes and verification

- Follow Laravel/package versions in the lockfiles and existing sibling conventions. Never change dependencies without approval.
- Add every new or renamed `env()` key to `.env.example` with a default and short comment; use `config()` outside config files.
- After code changes, run Pint, PHPStan, and PHP syntax lint on changed PHP files. Run focused PHPUnit tests for behavior changes; PHPUnit only, no Pest.
- Run `npm run build` after frontend changes. Refresh route cache after route changes when needed.
- Stage task files by explicit path. Exclude temporary files and unrelated changes. Follow the Git workflow and Git review gates when committing, pushing, opening a PR, or merging.
- If Pint changes PHP files, retain the fixes and rerun until clean. Fix new PHPStan errors; document any baseline addition.
- CaptainHook runs lint, Composer lock validation, and Pint. Commit limits are a 200-character subject and 72-character body.

## Shared runtime

- Feature sessions don't deploy or restart the shared stack. The deployment owner uses `make -C docker deploy DEPLOY_BY=<session name>` and the deploy guard.
- Treat production monitoring as read-only unless the user authorizes a specific operational change. Don't launch jobs, change NNTP/concurrency settings, restart, or deploy while observing.

## Project skills

- Read applicable skills before starting work; these links provide explicit routing from this guide.
- `.ai/skills/` owns the skill sources; tracked relative links in `.agents/skills/` and `.claude/skills/` provide native discovery.

- For performance, ingestion/storage concurrency, release admission/reset, NZB sufficiency, or cleanup correctness, read `.ai/skills/performance-validation/SKILL.md`.
- For production monitoring, queue diagnosis, or deployment coordination, read `.ai/skills/production-operations/SKILL.md`.
- For application architecture, tmux processing, API/config, testing, Manticore, or frontend work, read `.ai/skills/nntmux-development/SKILL.md`.
- These skills preserve detailed project guidance without loading every domain into every task. Laravel Boost guidance remains in `.github/instructions/laravel-boost.instructions.md` and `.ai/guidelines/`.

## Generated guidance

Laravel Boost and IDE instructions are generated or mirrored under `.github/instructions/` and `.ai/guidelines/`. Follow the relevant file for framework guidance; don't duplicate generated text here. Confirm installed package versions before relying on APIs, preserve sibling conventions, and use available Boost tools when they fit.

- Before Laravel/PHP development, read [.github/instructions/laravel-boost.instructions.md](.github/instructions/laravel-boost.instructions.md).
- Use Boost `search-docs` before changes depending on ecosystem APIs or version-specific behavior; copy-only edits don't require it.
- Prefer available Boost tools for schema inspection, read-only queries, browser logs, and project URL resolution.
- Boost guideline destinations in `config/boost.php` redirect generated framework guidance to `.github/instructions/laravel-boost.instructions.md`; keep this file and `CLAUDE.md` hand maintained.
