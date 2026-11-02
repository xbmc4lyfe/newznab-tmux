---
name: production-operations
description: Diagnose NNTmux production queues and service health using read-only runtime evidence, or coordinate an explicitly authorized shared-stack deployment.
---

# NNTmux production operations

Read repository `AGENTS.md` and matching `.ai/rules` first. Deployment and restart commands below apply only to the identified deployment owner and an authorized operational change. Read-only monitoring does not require deployment ownership.

## Identify the live runtime

- Discover the live Compose working directory from container labels and storage from bind mounts. A feature worktree or validation runtime is not production.
- Discover actual database tables/columns before querying runtime settings. Bound indexed reads and redact credentials, article subjects, and raw sensitive logs.
- Keep monitoring read-only unless the user authorizes a specific operational change. Do not launch processing/import jobs, change NNTP settings or concurrency, restart, or deploy as a side effect of observation.
- Preserve one source owner, one experiment executor, and the existing single deployment owner. Communicate handoffs and frozen source identities before transferring execution.

## Diagnose and report

- Compare interval deltas for deadlocks, row-lock waits/time, log waits, and physical/logical reads where relevant; cumulative totals alone are not current incidents.
- `Innodb_log_waits` means log-buffer flush waits; alone it does not prove insufficient redo-file capacity. Verify service impact before alerting and distinguish retry events from unique failures.
- Exclude documented authorized restart/cancellation cohorts, test containers, and standalone diagnostics from production failure rates. Investigate repeated service failures.
- A queued natural cycle or group with no eligible input is not a release stall. Verify creation with persisted release/NZB evidence and search visibility where relevant; collection deletion alone is not creation.
- Notify on verified actionable changes and deduplicate unchanged observations.
- Keep experiments, passing tests, review, merge, deployment, and live verification distinct. Review the final PR head, address findings, and preserve unrelated dirty checkout/runtime settings.

## Deploy ownership

- Only one session deploys the shared stack. Feature sessions test and prepare changes; they do not run `just build`, `just up`, `just deploy`, or `just restart`.
- The deploy owner uses `just deploy` (with `DEPLOY_BY` set in `docker/.env`, exported, or passed as `just deploy DEPLOY_BY=<session name>`), or `just deploy <service>` for named services. `just deploy-plan` (or `DRY_RUN=1 just deploy`) shows the plan without building or changing anything.
- `docker/bin/deploy` takes a lock directory (`docker/.deploy.lock` unless `DEPLOY_LOCK_DIR` is set), checks that the checkout is on `DEPLOY_GIT_BRANCH` (default `main`) and has no commits missing from `DEPLOY_GIT_REMOTE`, fast-forwards it, refuses leftover containers of removed services, `$` interpolation in `config/app.env` and any changed data-service definition, image or mounted config (unless `DEPLOY_RECREATE_DATA=1`), builds the image while the old stack runs, quiesces writers when a migration is pending or a data service is recreated (stops every app service and holds `nntmux-ctl verify barrier` for verifiers on other hosts until the rollout is healthy), runs `migrate` on its own, then runs `docker compose up -d --wait`. Changed services, and services whose mounted config changed since the last healthy deploy (`docker/.deploy.config`), are recreated; unchanged ones are left alone. It fails unless every running app container runs the built image.
- Workers drain on SIGTERM and finish in-flight units within their `stop_grace_period` (30 s `web`, 120 s default, 1920 s `fingerprint`, `verify` and `postprocess`). Active long jobs can delay a rollout. Never force-kill a worker to bypass the drain.
- Each attempt writes a `deploy result=started` line and a matching `deploy result=finished rc=<n>` line to `DEPLOY_LOG` (default `docker/.deploy.log`). Count a rollout as complete only when it finished with `rc=0`.
- A lock refusal exits with status 75. Report the active deployment and wait for its owner to finish before retrying an authorized deploy; do not bypass the lock. Remove a stale lock only after checking the log and that no deploy is running.
- After a rollout, check every service in `just docker ps`, the image's `org.opencontainers.image.revision` label against `docker/bin/source-revision` on the merged commit, `.deploy.log`, and fresh processing progress. A passing build or healthy old containers prove nothing.
- For an authorized single-service repair by the deployment owner, use `just docker restart s=<service>` (the worker drains first) or `just deploy <service>` to apply pending changes to that service, not a full rebuild.
- Existing Git authorization and deployment requirements still apply. The one-time cutover from PHP follows `rust/CUTOVER.md`.
