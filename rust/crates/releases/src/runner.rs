//! `multiprocessing:releases`: one round of release processing over every group with collections.
//!
//! A ready-window pass over every group goes first, so small uploads aren't held up behind a long
//! reconciliation. Full passes follow, at most `release_threads` at a time. While a round waits on
//! its slowest groups, a group whose pass finished starts another one, at most once per rolling
//! interval, instead of idling. Maintenance runs once when the round ends.

use std::time::{Duration, Instant};

use anyhow::{Result, anyhow, bail};
use nntmux_core::parsed_or;
use nntmux_runtime::Shutdown;
use tokio::task::JoinSet;
use tracing::{error, info};

use crate::settings::{Limits, Settings, stop_requested};
use crate::{Releases, maintenance, process};

/// Groups that are active or backfilling and hold collections.
async fn groups_with_collections(releases: &Releases) -> Result<Vec<i64>> {
    let ids: Vec<u32> = sqlx::query_scalar(
        "SELECT g.id FROM usenet_groups AS g WHERE (g.active = 1 OR g.backfill = 1) \
         AND EXISTS (SELECT 1 FROM collections WHERE collections.groups_id = g.id)",
    )
    .fetch_all(&releases.verify.db)
    .await?;
    Ok(ids.into_iter().map(i64::from).collect())
}

/// Runs one round. A group's failure is logged and the rest carry on, as a worker's exit was.
pub async fn run_round(releases: &Releases, stop: &Shutdown) -> Result<()> {
    let db = &releases.verify.db;
    let settings = Settings::load(db).await?;
    let limits = Limits::from_env()?;
    let groups = groups_with_collections(releases).await?;
    if groups.is_empty() {
        // Retention and categorization don't depend on new collections arriving.
        return maintenance::run(releases, &settings).await;
    }
    let threads = settings.release_threads.max(1) as usize;
    let rolling: u64 = parsed_or::<i64>("CBP_RELEASE_ROLLING_INTERVAL", 60)?.max(0) as u64;

    let mut ready = JoinSet::new();
    let mut queue = groups.clone().into_iter();
    loop {
        while ready.len() < threads
            && !stop.requested()
            && let Some(group) = queue.next()
        {
            let (releases, settings) = (releases.clone(), settings.clone());
            ready.spawn(async move {
                let outcome =
                    process::process_ready_release_window(&releases, &settings, group).await;
                (group, outcome)
            });
        }
        let Some(joined) = ready.join_next().await else {
            break;
        };
        let (group, outcome) = joined?;
        if let Err(failure) = outcome {
            error!(
                group,
                error = format!("{failure:#}"),
                "ready release pass failed"
            );
        }
    }
    if stop.requested() || stop_requested(db).await? {
        return Ok(());
    }

    run_full_passes(
        releases, &settings, &limits, &groups, threads, rolling, stop,
    )
    .await?;
    if stop.requested() || stop_requested(db).await? {
        return Ok(());
    }
    maintenance::run(releases, &settings).await
}

async fn run_full_passes(
    releases: &Releases,
    settings: &Settings,
    limits: &Limits,
    groups: &[i64],
    threads: usize,
    rolling: u64,
    stop: &Shutdown,
) -> Result<()> {
    let db = &releases.verify.db;
    let mut first_runs: Vec<i64> = groups.iter().rev().copied().collect();
    // Groups whose first run finished, each with when it may start again.
    let mut follow_ups: Vec<(i64, Instant)> = Vec::new();
    let mut running = JoinSet::new();
    let mut first_runs_active = 0usize;
    let pass_limit = Duration::from_secs(
        parsed_or::<u64>(
            "NN_CONCURRENCY_TIMEOUT",
            parsed_or::<u64>("NN_MULTIPROCESSING_MAX_CHILD_TIME", 1800)?,
        )?
        .max(1),
    );
    loop {
        // A brief database error on this poll must not cancel the passes in flight.
        let stopping = stop.requested() || stop_requested(db).await.unwrap_or(false);
        if !stopping {
            while running.len() < threads {
                let (group, first) = if let Some(group) = first_runs.pop() {
                    (group, true)
                } else if let Some(position) = (first_runs_active > 0)
                    .then(|| follow_ups.iter().position(|(_, at)| *at <= Instant::now()))
                    .flatten()
                {
                    (follow_ups.remove(position).0, false)
                } else {
                    break;
                };
                first_runs_active += usize::from(first);
                let (releases, settings, limits) = (releases.clone(), settings.clone(), *limits);
                let started = Instant::now();
                running.spawn(async move {
                    let pass = process::run_group_pass(&releases, &settings, &limits, group);
                    // A pass stuck behind a lost lock or a hung query gives up, as a worker was killed.
                    let outcome = match tokio::time::timeout(pass_limit, pass).await {
                        Ok(outcome) => outcome,
                        Err(_) => Err(anyhow!(
                            "the release pass exceeded {} seconds",
                            pass_limit.as_secs()
                        )),
                    };
                    (group, first, started, outcome)
                });
            }
        }
        // Follow-ups not started when the last first run ends are dropped, so they never extend the
        // round beyond its slowest group's tail.
        let joined = tokio::select! {
            joined = running.join_next() => joined,
            () = tokio::time::sleep(Duration::from_secs(1)), if first_runs_active > 0 && !follow_ups.is_empty() => continue,
        };
        let Some(joined) = joined else {
            if stopping || first_runs.is_empty() {
                return Ok(());
            }
            bail!("the release round stopped with groups left");
        };
        let (group, first, started, outcome) = match joined {
            Ok(finished) => finished,
            Err(failure) => {
                // Which group panicked is in the panic's own log; a first run's count can't be told
                // apart here, so the round stops offering follow-ups rather than outlive its tail.
                error!(error = %failure, "a release pass panicked");
                first_runs_active = first_runs_active.saturating_sub(1);
                continue;
            }
        };
        first_runs_active -= usize::from(first);
        match outcome {
            Ok(()) => {
                info!(group, "finished release processing");
                if rolling > 0 && (first_runs_active > 0 || !first_runs.is_empty()) {
                    follow_ups.push((group, started + Duration::from_secs(rolling)));
                }
            }
            Err(failure) => error!(group, error = format!("{failure:#}"), "release pass failed"),
        }
    }
}
