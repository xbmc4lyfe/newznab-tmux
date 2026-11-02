---
paths:
  - '**'
---

# Git workflow and review gates

A human prompt or goal authorizes agents to commit task changes, push feature branches, open pull requests, and squash-merge reviewed feature pull requests into `rust-native` in `xbmc4lyfe/newznab-tmux`. Stay within the task. Deployment, production changes, dependency changes, force pushes, and changes to repository protections need separate authorization.

## Prepare the branch

- Start each new task in an isolated Git worktree on a new `codex/` branch whose name is absent from the remote. Reuse that task's worktree while continuing it; an explicitly requested existing PR keeps its branch. Keep `rust-native` as the PR base and never develop or push directly on it.
- Verify the remote points to `xbmc4lyfe/newznab-tmux`, fetch its `rust-native` at task start, and create the worktree/branch from that fetched commit. `NNTmux/newznab-tmux:master` is a separate upstream and is not the feature-branch base.
- During active work, fetch and integrate newer `rust-native` commits every 30–60 minutes. Refresh when resuming a session if the last fetch is older than 30 minutes. Preserve unrelated and uncommitted work; never reset or discard it to synchronize. Resolve conflicts and rerun affected checks.
- Fetch and integrate the fork's current `rust-native` immediately before local review and PR creation, and recheck it before merging. An advanced destination requires updating the branch, affected verification, and fresh local and PR reviews.
- After each PR merge, fetch the fork's `rust-native` again before creating the next task's worktree and new branch. Start from the newly fetched commit, not the previous task branch or a stale local `rust-native`.
- Stage files with explicit paths. Exclude scratch files, logs, and unrelated changes.
- Run the checks required for the changed files. Documentation changes need prose and consistency checks. App changes also need relevant tests and lint.

## Review locally before opening a PR

1. Commit the changes and record the head commit hash and destination base commit hash.
2. Obtain an independent local review of the complete diff against that base using Antigravity (via an independent subagent) or Codex (via a Codex subagent or separate command-line process). If local Codex does not work, you can substitute an independent subagent within whatever harness you are using to do a local review. The implementation agent can't approve its own work.
3. Only fix P0 and P1 findings; P2, P3, P4, and lower-priority findings can be ignored. When P0 or P1 fixes are made, repeat relevant checks and obtain another review. Open the PR once the final review reports no unresolved P0 or P1 findings and gives an explicit go-ahead for the committed head.

You may push the feature branch before review. Opening a PR still requires a clean local review with no P0 or P1 findings.

For a Codex command-line review, use the installed command's supported flags. For example:

```bash
codex exec --model gpt-6.1-sol \
  -c model_reasoning_effort=high -c service_tier=default \
  --sandbox read-only --ephemeral review --base origin/rust-native
```

Record the reviewer (Antigravity or Codex), model, effort, speed (when applicable), base commit hash, head commit hash, findings, and final verdict. Read the result. A successful process exit doesn't prove a clean review.

## Review the PR before merging

1. Open the PR against `xbmc4lyfe/newznab-tmux:rust-native` and either obtain an independent Antigravity review of the PR or comment `@codex review`.
2. Only fix P0 and P1 findings; P2, P3, P4, and lower-priority findings can be ignored. For P0 and P1 findings, check each finding against the code, reproduce bugs when practical, fix valid findings, run relevant checks, reply in the finding's thread with the fix and evidence (or explain with evidence why the finding doesn't apply), and resolve the thread after replying.
3. Obtain another review from Antigravity or request `@codex review` again after P0/P1 fixes or replies that explain why a P0/P1 finding doesn't apply. Repeat until the reviewer gives an explicit approval or go-ahead for the current head with no unresolved P0 or P1 findings.
4. Wait for required checks to pass. Verify that the remote PR head and destination base still match the reviewed revisions, then squash-merge the feature PR. If the destination base has changed, update the branch, rerun relevant checks, and repeat both reviews before merging.

Every content change after a review invalidates that review. Make the needed changes, rerun checks, and obtain a fresh review with no P0 or P1 findings before opening or merging the PR. This applies to documentation and review fixes too.

### When GitHub review credits run out

Use this fallback when the GitHub Codex app reports that review credits have run out, or when using an independent Antigravity review on the open PR.

- After the PR is open, obtain a fresh independent local review (via Antigravity, Codex, or an independent subagent within whatever harness you are using if local Codex does not work) of its current head against its current destination base. The earlier review before PR creation isn't a substitute.
- When using Codex for this review, use `gpt-6.1-sol` or a stronger model, `high` reasoning effort, and normal speed (`service_tier=default`), and verify the resolved settings. When using Antigravity, use an independent subagent given the contract and diff without the implementer's conclusions.
- Require no unresolved P0 or P1 findings and an explicit go-ahead (P2, P3, P4, and lower-priority findings can be ignored). After any change, repeat the review on the new head.
- Record the reviewer, reviewed base and head commit hashes, model, effort, and full review result in the PR. Address existing GitHub P0 and P1 findings and resolve their threads before merging.
- Required checks and repository protections still apply. If an approval requirement blocks the fallback, report the blocker. Don't bypass it.

## Upstream synchronization

Upstream is `NNTmux/newznab-tmux:master`. A separate request to synchronize upstream authorizes that workflow. Preserve upstream history with a merge commit. Feature work in this fork uses a squash merge. Apply the same review and verification gates.
