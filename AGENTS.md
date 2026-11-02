# Codex routing

These model rules apply to Codex subagents only; Claude uses `CLAUDE.md`. Keep the human-selected main-session model unchanged. Delegate bounded independent work when useful; do small coupled work directly.

| Delegated task | Model | Effort |
| --- | --- | --- |
| File/symbol inventory, source lookup, log summarization, mechanical edits | `gpt-6-luna` | `high` |
| Bounded Rust implementation or focused correctness review | `gpt-6.1-sol` | `medium` |
| Cross-service debugging, transaction reasoning, concurrency fixes | `gpt-6.1-sol` | `high` |
| Ambiguous architecture, security boundaries, release/storage safety review | `gpt-6-astra` | `high` |

- Choose complexity by uncertainty, interacting state, and failure impact; a short diff can require the strongest route.
- Set both `model` and `reasoning_effort` explicitly on native `spawn_agent`; use `fork_turns="none"` with a self-contained brief when overrides require it. Command-line children use `--model` and `-c model_reasoning_effort=...`.
- Include goal, relevant paths/skills, constraints, file ownership, validation, and completion evidence. Give independent reviewers the contract and diff without the implementer's conclusions.
- Escalate when evidence shows the task exceeds its route; give a concrete reason. Reserve `xhigh`/`max` for unresolved difficult reasoning. Check advertised model/effort support; report unavailable overrides rather than silently inheriting or bypassing permissions.
- Parallelize independent reads; give each writer exclusive files or an isolated worktree.

# Git workflow and code review

A human prompt or goal authorizes agents to commit task changes, push a feature branch, open a PR, and squash-merge it into `rust-native`, the default branch, in `xbmc4lyfe/newznab-tmux` after all review gates pass. `master` is kept only for the PHP history: never base work on it or target it. Follow [.ai/rules/git-workflow.md](.ai/rules/git-workflow.md). This permission doesn't authorize deployment, production changes, or dependency changes.

- Before opening a PR, obtain an independent local review with no P0 or P1 findings using Antigravity (via an independent subagent) or Codex (via a Codex subagent or separate command-line process); if local Codex does not work, you can substitute a subagent within whatever harness you are using to do a local review. Only fix P0 and P1 findings; P2, P3, P4, and lower-priority findings can be ignored.
- After opening the PR, obtain a PR review from Antigravity or request `@codex review`. Only fix P0 and P1 findings. For P0 and P1 findings, address and reply to each finding, resolve its review thread, and obtain another review after fixes or an explanation that a finding doesn't apply.
- Merge only after the reviewer (Antigravity or Codex) gives an explicit approval or go-ahead for the current PR head with no unresolved P0 or P1 findings and required checks pass. Every change after a review requires a fresh review with no P0 or P1 findings.
- If the GitHub Codex app reports exhausted review credits, use a fresh independent local review of the current PR head via Antigravity or Codex (`gpt-6.1-sol` or a stronger model, `high` effort, and normal speed), or substitute a subagent within whatever harness you are using if local Codex does not work. Record the review and commit hash in the PR.
- Feature pull requests target `rust-native`. Moving production from the PHP stack on `master` to `rust-native` needs Allen's explicit go-ahead ([rust/CUTOVER.md](rust/CUTOVER.md)). Upstream synchronization with `NNTmux/newznab-tmux` uses a merge commit and a separate request.

## Code review rules

- Review the complete PR diff against its destination branch. Report actionable P0 and P1 findings and identify the reviewed head commit hash; P2 and lower are non-blocking. An exit code alone isn't approval.
- Check the review gates, macOS/Linux platform policy, and [performance requirements](.ai/rules/rust-performance.md) when they apply. Approve once the current revision has no unresolved P0 or P1 findings.

# NNTmux agent guide

NNTmux is an all-Rust Usenet indexer on this branch, with no PHP. One image holds `nntmux-web`, `nntmux-worker <role>` and `nntmux-ctl`. Read [rust/WORKSTREAMS.md](rust/WORKSTREAMS.md) for how the rewrite is organised and its rules, and [rust/CUTOVER.md](rust/CUTOVER.md) for the cutover. The PHP lives only at the `php-final` tag. Load a project skill below when its domain applies.

## Before planning or editing

- Start every new implementation, fix, or feature in an isolated Git worktree on a new `codex/` branch that does not exist on the remote (workstreams use `codex/rn-<workstream>-<topic>`). Never develop on a shared checkout, `rust-native` or `master`.
- Fetch the latest `rust-native` from `xbmc4lyfe/newznab-tmux`, verify the remote URL, and base the branch on the fetched commit. Not `NNTmux/newznab-tmux`.
- While working, fetch and integrate newer base commits every 30-60 minutes and at the start of a resumed session. Do it again before the first local review and before creating the PR. Preserve unrelated and uncommitted work; never reset or discard it. After a merge, fetch again before the next task's branch.
- Read [.ai/rules/index.md](.ai/rules/index.md), every rule matching the target paths, and search `.ai/rules` for task keywords. Record durable shared rules there, not in personal memory.
- Follow sibling conventions and reuse existing components. Dependencies and new base directories need user approval. Create documentation only when requested. Keep replies concise.

## Commands and verification

- Use macOS or Linux; no Windows hosts or WSL. Run host tools by default; use `just docker ...` only for stack lifecycle or an explicit containerized request. See [.ai/rules/command-execution.md](.ai/rules/command-execution.md).
- Set `CARGO_BUILD_JOBS=4` (the recipes default to it). The Mac is shared by every worktree: test your crate with `just test -p <crate>` while working, and run the whole workspace only before a PR.
- `just check` runs `cargo fmt --all --check`, `cargo clippy --workspace --all-targets --locked -- -D warnings` and `python3 rust/porting/check.py`. `just test` starts `rust/compose.test.yml` (MariaDB, Redis, Manticore) and runs `cargo test --locked`. Run `just frontend` after changes under `rust/frontend`.
- Behavior changes need a test through the real handler or role. Pure logic uses golden fixtures, database logic uses `nntmux_testkit::TestDb`, HTTP clients use `wiremock`. Mark a ledger entry in `rust/porting/ledger/` as `ported` in the PR that ports it.
- Rust only: no `extern "C"`, `#[no_mangle]`, `cdylib`, or shelling out to PHP. `unsafe` stays denied except SIMD in `yenc`, each with a `// SAFETY:` comment.
- Stage task files by explicit path. Exclude temporary files and unrelated changes. Commit limits are a 200-character subject and 72-character body.
- Add each new or renamed environment variable, with a default and a short comment, to `docker/config/app.env.example` (or `docker/.env.example` for Compose settings). Read it through `nntmux_core::Config`.

## Performance

Hot-path changes need benchmark evidence and must not regress common paths or p99. Follow [.ai/rules/rust-performance.md](.ai/rules/rust-performance.md): frozen commits and inputs, paired randomized `--release` runs, raw results kept, and a 1.5x gain on common paths and p99 for changes made for speed.

## Standing authorization for project session coordination

- The user explicitly authorizes agents to send deployment handoffs, status messages, and related coordination messages to other sessions associated with this repository/folder without asking again.
- Identify the deployment owner using the session's title, project association, and recent context. Names such as "Redeploy on upstream master changes" and "Deployment manager" are examples, not required exact matches.
- If a recipient identifies another session as the deployment owner or an appropriate contact, agents may follow that referral and message that session or any other session associated with this repository/folder without renewed confirmation.
- This standing authorization covers session messages only. It does not itself authorize deployment, restarts, processing jobs, other production changes, or external communications. Preserve the single deployment owner and all deployment guards.

## Shared runtime and deployment

- Feature sessions never deploy, restart, or build for the shared stack. Only the deployment owner runs `just deploy`. Treat production monitoring as read-only unless the user authorizes a specific operational change; don't launch jobs, change NNTP or concurrency settings, restart, or deploy while observing.
- One image (`APP_IMAGE`, default `nntmux/rust:local`) runs every service in `docker/docker-compose.yml`. `just deploy` runs `docker/bin/deploy`. It needs `DEPLOY_BY` (in `docker/.env`, or `just deploy DEPLOY_BY=<session name>`).
- The deploy takes a lock directory (`DEPLOY_LOCK_DIR`, default `docker/.deploy.lock`; exit 75 if held). The checkout must be on `DEPLOY_GIT_BRANCH` (default `rust-native`) with no commits missing from `DEPLOY_GIT_REMOTE`; it is fast-forwarded. `DEPLOY_SYNC_REMOTE=0` skips this for offline tests only.
- Before changing anything it refuses running containers of services the Compose file no longer defines, `$` interpolation in `docker/config/app.env`, and a changed Compose definition, image or mounted config on a data service (unless `DEPLOY_RECREATE_DATA=1`, which recreates it first, in a planned window). It then builds the image while the old stack runs, runs `migrate` on its own (a failure leaves the old stack serving), and runs `docker compose up -d --wait` (timeout `DEPLOY_HEALTH_TIMEOUT`, 600 s). Changed services, and services whose mounted config changed since the last healthy deploy, are recreated; the rest are left alone. It fails unless every running app container runs the built image. Stop remote verification workers before a deploy that adds a migration.
- Workers drain on SIGTERM: they stop claiming work, finish in-flight units and exit within `stop_grace_period` (30 s `web`, 120 s default, 1920 s `fingerprint`, `verify` and `postprocess`). Long jobs can delay a rollout. There are no drain barriers, stamps or batching delays.
- `just deploy-plan` (or `DRY_RUN=1 just deploy`) prints the plan without building or changing anything. `just deploy <service>` rolls out named services. Each real attempt writes a `started` line and a matching `finished rc=<n>` line to `DEPLOY_LOG` (default `docker/.deploy.log`); that file is the deployment history. Don't remove a lock for a live deploy; inspect the log first. Don't retry an unresolved failure or bypass the lock.
- A fetch, checkout update or image build alone isn't a deployment. Before reporting success, check that every service is healthy (`just docker ps`), that the running image's `org.opencontainers.image.revision` label matches the merged commit, that `DEPLOY_LOG` has `finished rc=0`, and that processing is moving. Healthy preexisting containers or a successful SSH command prove nothing.
- Stack recipes: `just docker help`. Admin commands run in a one-off container: `just docker ctl cmd="search inspect"`. Run `just docker backup` before a risky change (bundled MariaDB only).
- The cutover from PHP follows [rust/CUTOVER.md](rust/CUTOVER.md) step by step, only with Allen's go-ahead.

### Split production stack (deploy-user@192.168.1.113 and deploy-user@192.168.1.111)

- Project memory says the database moved to an M4 at 192.168.1.110. The deployment owner must confirm the current hosts before acting; the facts below may be out of date.
- After merging a reviewed PR, send the deployment-owner chat the PR URL, merged commit and validation results. It coordinates the next rollout; feature chats must not start a competing build, deploy or polling loop. Respect any maintenance or live-observation hold. (The former `m5stephen` / `air@192.168.1.111` host is retired and deleted.)
- `allen@192.168.1.113` hosts the Compose application (`web` and the worker roles), `redis`, `manticore`, `proxy`, `mailpit`, and monitoring. `allen@192.168.1.111` hosts the external MariaDB (`DB_HOST=192.168.1.111` in `docker/config/app.env`). Noninteractive SSH omits Homebrew from `PATH`: prepend `/opt/homebrew/bin:/opt/homebrew/sbin` and set `DOCKER_CONTEXT=orbstack`. The host needs just 1.43 or newer. Confirm the context resolves to `/Users/allen/.orbstack/run/docker.sock` before control actions.
- The persistent runtime is `/Users/allen/git/newznab-tmux/docker` (candidate checkout `/Users/allen/git.xbmc4lyfe/newznab-tmux`). Set `DEPLOY_BY`, `COMPOSE_FILE` (the base file plus `/Users/allen/.config/nntmux-db/compose.cutover.yml`), `DC='docker compose --project-directory /Users/allen/git/newznab-tmux/docker'`, `DEPLOY_LOCK_DIR` and `DEPLOY_LOG` in `docker/.env`, so rollouts are `just deploy`, `just deploy-plan` or `just deploy <service>`. Keep one lock and one log; a new clone must not create a second guard or history.
- When running from a separate candidate checkout, link its `docker/.env` and `docker/config/app.env` to the runtime's files and check both links resolve. Keep secrets out of commits and image layers. Compare every tracked bind-mounted config (Caddy, Redis, Manticore, Prometheus, Grafana) with the reviewed candidate before rollout; if they differ, stop until the owner reconciles them.
- Fetch and integrate the base branch before freezing each candidate. Reuse the Docker layer cache (no prune, no `--no-cache` for routine updates). Keep `COMPOSE_FILE`, `DC` and the Docker context the same across build, deploy and health checks.

## Project skills

- Read applicable skills before starting work. `.ai/skills/` owns the sources; relative links in `.agents/skills/` and `.claude/skills/` provide native discovery.
- Performance, ingestion/storage concurrency, release admission/reset, NZB sufficiency, or cleanup correctness: `.ai/skills/performance-validation/SKILL.md`.
- Production monitoring, queue diagnosis, or deployment coordination: `.ai/skills/production-operations/SKILL.md`.
- Architecture, roles, API/config, testing, Manticore, or frontend work: `.ai/skills/nntmux-development/SKILL.md`.
