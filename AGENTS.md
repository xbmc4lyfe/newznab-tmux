# Codex routing

These model rules apply to Codex subagents only; Claude uses `CLAUDE.md`. Keep the human-selected main-session model unchanged. Delegate bounded independent work when useful; do small coupled work directly.

| Delegated task | Model | Effort |
| --- | --- | --- |
| File/symbol inventory, source lookup, log summarization, mechanical edits | `gpt-6-luna` | `high` |
| Bounded Laravel implementation or focused correctness review | `gpt-6.1-sol` | `medium` |
| Cross-service debugging, transaction reasoning, concurrency fixes | `gpt-6.1-sol` | `high` |
| Ambiguous architecture, security boundaries, release/storage safety review | `gpt-6-astra` | `high` |

- Choose complexity by uncertainty, interacting state, and failure impact; a short diff can require the strongest route.
- Set both `model` and `reasoning_effort` explicitly on native `spawn_agent`; use `fork_turns="none"` with a self-contained brief when overrides require it. CLI children use `--model` and `-c model_reasoning_effort=...`.
- Include goal, relevant paths/skills, constraints, file ownership, validation, and completion evidence. Give independent reviewers the contract and diff without the implementer's conclusions.
- Escalate when evidence shows the task exceeds its route; give a concrete reason. Reserve `xhigh`/`max` for unresolved difficult reasoning. Check advertised model/effort support; report unavailable overrides rather than silently inheriting or bypassing permissions.
- Parallelize independent reads; give each writer exclusive files or an isolated worktree. Keep the source/experiment/deployment ownership rules below.

# PR Review and merging

When asked to review and merge a PR, follow the below set of strict guidance to ensure proper testing and validation is done before you merge the PR.

## Fork repository
Fork's main branch: `master`
Git repository: `https://github.com/xbmc4lyfe/newznab-tmux.git`

## Upstream repository
Upstream main branch: `master`
Git repository: `https://github.com/NNTmux/newznab-tmux.git`


- Update the PR against the intended destination branch before submitting
- Create the PR and then wait for Codex to review
- Check each finding against the code and reproduce it where practical. Fix real bugs, run relevant tests, and push the fixes when authorized. Reply with the evidence that it is not an issue or if its real. Then create a fix commit, and reply to the review comment that you fixed it through reproduction and how you fixed it then resolve the commet. Do this for all unresolved comments in the PR.
- Once updates are pushed, comment `@codex re-review`. Repeat as needed until Codex approves the current head and required checks pass. New commits need another review before merging.
- When Codex finally gives a g2g then squash and merge feature branches into your own repository; use a merge commit for upstream synchronization into your fork. If the repository disallows that method, flag it before merging.

**Wait for Codex's go-ahead before merging.**

# NNTmux agent guide

Laravel 13 Usenet indexer. Use this file for repo-wide defaults; load a project skill below when its domain applies.

## Before planning or editing

- Read [.ai/rules/index.md](.ai/rules/index.md), every rule matching the target paths, and search `.ai/rules` for task keywords.
- Record durable shared rules with Boost's `record-rule`; do not substitute personal memory.
- Follow existing sibling conventions and reuse existing components. Keep dependencies and new base directories subject to user approval.
- Create documentation only when requested. Keep replies concise.

## Commands

- Run Artisan, Composer, npm, Pint, PHPStan, and tests. Sail/`make` is for Compose lifecycle or an explicit containerized request. See `.ai/guidelines/sail/core.blade.php`.
- Examples: `php artisan test --compact --filter=TestName`, `./vendor/bin/pint --dirty`, `./vendor/bin/phpstan analyse --memory-limit=2G`, `npm run build` after frontend edits.

## Changes and verification

- Follow Laravel/package versions in the lockfiles and existing sibling conventions. Never change dependencies without approval.
- Add every new or renamed `env()` key to `.env.example` with a default and short comment; use `config()` outside config files.
- After code changes, run Pint, PHPStan, and PHP syntax lint on changed PHP files. Run focused PHPUnit tests for behavior changes; PHPUnit only, no Pest.
- Run `npm run build` after frontend changes. Refresh route cache after route changes when needed.
- Do not commit or push unless the user explicitly authorizes that request in this conversation. Stage every created/modified project file by explicit path; exclude temporary and unrelated files.
- If Pint changes PHP files, retain the fixes and rerun until clean. Fix new PHPStan errors; document any baseline addition.
- CaptainHook runs lint, Composer lock validation, and Pint. Commit limits are a 200-character subject and 72-character body.

## Shared runtime

- Feature sessions do not deploy or restart the shared stack. The deployment owner uses `make -C docker deploy DEPLOY_BY=<session name>` and the deploy guard.
- Treat production monitoring as read-only unless the user authorizes a specific operational change. Do not launch jobs, change NNTP/concurrency settings, restart, or deploy while observing.

## Project skills

- Read applicable skills before starting work; these links provide explicit routing from this guide.
- `.ai/skills/` owns the skill sources; tracked relative links in `.agents/skills/` and `.claude/skills/` provide native discovery.

- For performance, ingestion/storage concurrency, release admission/reset, NZB sufficiency, or cleanup correctness, read `.ai/skills/performance-validation/SKILL.md`.
- For production monitoring, queue diagnosis, or deployment coordination, read `.ai/skills/production-operations/SKILL.md`.
- For application architecture, tmux processing, API/config, testing, Manticore, or frontend work, read `.ai/skills/nntmux-development/SKILL.md`.
- These skills preserve detailed project guidance without loading every domain into every task. Laravel Boost guidance remains in `.github/instructions/laravel-boost.instructions.md` and `.ai/guidelines/`.

## Generated guidance

Laravel Boost and IDE instructions are generated or mirrored under `.github/instructions/` and `.ai/guidelines/`. Follow the relevant file for framework guidance; do not duplicate generated text here. Confirm installed package versions before relying on APIs, preserve sibling conventions, and use available Boost tools when they fit.

- Before Laravel/PHP development, read [.github/instructions/laravel-boost.instructions.md](.github/instructions/laravel-boost.instructions.md).
- Use Boost `search-docs` before changes depending on ecosystem APIs or version-specific behavior; copy-only edits do not require it.
- Prefer available Boost tools for schema inspection, read-only queries, browser logs, and project URL resolution.
- Boost guideline destinations in `config/boost.php` redirect generated framework guidance to `.github/instructions/laravel-boost.instructions.md`; keep this file and `CLAUDE.md` hand maintained.
