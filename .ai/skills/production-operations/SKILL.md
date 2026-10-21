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
- Deployments use per-service input fingerprinting (`just deploy-plan` to inspect): non-runtime edits (such as documentation or tests) skip rebuilds and container restarts entirely, and scoped changes rebuild/recreate only the affected service(s). Batch merged changes for one deployment.
- The deploy owner uses `just deploy` (with `DEPLOY_BY` configured in `docker/.env` or exported, or `just deploy DEPLOY_BY=<session name>`), or `just deploy <component>` / `just deploy-<component>` (`web`, `horizon`, `scheduler`, `indexer`, `proxy`, `monitoring`, `redis`, `manticore`, `mariadb`, `mailpit`, `grafana`, `prometheus`) for a targeted component rollout. `bin/deploy-guard` serializes deploys via `docker/.deploy.lock`, records them in `docker/.deploy.log`, and skips a rebuild or rollout when nothing affecting runtime services changed (`FORCE=1` overrides).
- A guard refusal exits with status 75. Report the active deployment and wait for its owner to finish before retrying an authorized deploy; do not bypass the guard. Do not remove a lock for a live PID (the guard clears stale locks).
- For an authorized single-service repair by the deployment owner, use `just docker restart s=<service>` (or `just deploy <service>` to apply pending changes to that component), not a full rebuild.
- Existing Git authorization and deployment-guard requirements still apply.
