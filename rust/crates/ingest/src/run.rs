//! The multiprocessing runners, as tasks instead of child processes, and the worker roles.

use std::sync::Arc;
use std::time::Duration;

use anyhow::{Context, Result};
use async_trait::async_trait;
use nntmux_core::parsed_or;
use nntmux_runtime::{Ctx, Shutdown, busy};
use nntmux_verify::cache::Cache;
use nntmux_verify::context::Verify;
use redis::AsyncCommands;
use sqlx::{FromRow, MySqlPool};
use tokio::sync::Semaphore;
use tokio::task::JoinSet;
use tracing::{error, info, warn};

use crate::backfill::{Order, Target};
use crate::config::IngestConfig;
use crate::get_range::{RangeMode, RangeRequest, get_range};
use crate::groups;
use crate::nntp::Nntp;
use crate::scan::Ingest;
use crate::store::{StoreHooks, VerifyHooks};

/// `Cache::forever` key PHP rotated with. Losing it only restarts the rotation.
const ROTATION_KEY: &str = "ingest:binaries:rotation:last-group";
/// `get_range` skipped the first 20k articles past the cursor before splitting into ranges.
const SAFE_SKIP: i64 = 20_000;
const PAUSED_REST: Duration = Duration::from_secs(5);
const FAILED_REST: Duration = Duration::from_secs(60);

/// Opens news server connections, one per job.
#[async_trait]
pub trait Connector: Send + Sync {
    async fn connect(&self) -> Result<Box<dyn Nntp>>;
}

/// One unit of header work, on its own connection.
#[derive(Clone, Debug, PartialEq, Eq)]
pub enum Job {
    UpdateHeaders { group: String, max: u64 },
    Range(RangeJob),
    Backfill { group: String, target: Target },
}

/// `RangeRequest` with what a scheduling comparison needs.
#[derive(Clone, Debug, PartialEq, Eq)]
pub struct RangeJob {
    pub mode: RangeMode,
    pub group: String,
    pub first: u64,
    pub last: u64,
    pub contiguous: bool,
    pub repair: bool,
}

/// The jobs for one group, which run in order and never overlap.
#[derive(Clone, Debug, PartialEq, Eq)]
pub struct GroupJobs {
    pub group: u32,
    pub jobs: Vec<Job>,
}

/// A group's safe update position.
#[derive(Clone, Debug, FromRow)]
pub struct SafeGroup {
    pub id: u32,
    pub groupname: String,
    pub our_last: u64,
    pub their_last: u64,
}

/// Plans one safe update pass, starting after the last group it visited. A group close to
/// caught up takes one bounded header visit. One further behind is split into contiguous ranges,
/// which only ever advance the cursor in order, and the first of them repairs missing articles.
pub fn plan_safe_binaries(
    groups: &[SafeGroup],
    last_group: u32,
    max_messages: u64,
    max_headers: u64,
) -> Vec<GroupJobs> {
    let (after, before): (Vec<&SafeGroup>, Vec<&SafeGroup>) =
        groups.iter().partition(|group| group.id > last_group);
    let max_messages = max_messages.max(1);
    after
        .into_iter()
        .chain(before)
        .map(|group| {
            let visit = Job::UpdateHeaders {
                group: group.groupname.clone(),
                max: max_messages,
            };
            let behind = i64::try_from(group.their_last).unwrap_or(i64::MAX)
                - i64::try_from(group.our_last).unwrap_or(i64::MAX)
                - SAFE_SKIP;
            let doubled = i64::try_from(max_messages.saturating_mul(2)).unwrap_or(i64::MAX);
            let jobs = if group.our_last == 0 || behind <= doubled {
                vec![visit]
            } else {
                let count = u64::try_from(behind).unwrap_or(0);
                let end = group.our_last + count.min(max_headers);
                let mut jobs = Vec::new();
                let mut first = group.our_last + 1;
                while first <= end {
                    let last = (first + max_messages - 1).min(end);
                    jobs.push(Job::Range(RangeJob {
                        mode: RangeMode::Binaries,
                        group: group.groupname.clone(),
                        first,
                        last,
                        contiguous: true,
                        repair: first == group.our_last + 1,
                    }));
                    first = last + 1;
                }
                jobs
            };
            GroupJobs {
                group: group.id,
                jobs,
            }
        })
        .collect()
}

/// Plans one safe backfill pass over the group furthest behind in the configured order. Ranges
/// run in parallel and defer to part repair if they can't run.
pub fn plan_safe_backfill(
    group: &str,
    our_first: i64,
    their_first: i64,
    quantity: i64,
    threads: i64,
    max_messages: i64,
) -> Vec<Job> {
    let count = our_first - their_first;
    if count <= 0 || max_messages < 1 {
        return Vec::new();
    }
    let budget = quantity.saturating_mul(threads);
    let wanted = if count > budget { budget } else { count };
    // Rounded up.
    let chunks = (wanted + max_messages - 1) / max_messages;
    // The oldest chunk stops at the server's first article, rather than running below it.
    let lowest = their_first.max(1);
    (0..chunks)
        .filter_map(|index| {
            let first = (our_first - index * max_messages - max_messages).max(lowest);
            let last = our_first - index * max_messages - 1;
            if last < first {
                return None;
            }
            Some(Job::Range(RangeJob {
                mode: RangeMode::Backfill,
                group: group.to_owned(),
                first: u64::try_from(first).ok()?,
                last: u64::try_from(last).ok()?,
                contiguous: false,
                repair: false,
            }))
        })
        .collect()
}

/// The `ORDER BY` of `backfill_order`.
fn safe_backfill_order(order: u32) -> &'static str {
    match order {
        1 => "ORDER BY first_record_postdate DESC",
        2 => "ORDER BY first_record_postdate ASC",
        3 => "ORDER BY name ASC",
        4 => "ORDER BY name DESC",
        5 => "ORDER BY a.last_record DESC",
        _ => "ORDER BY a.last_record ASC",
    }
}

/// What a pass did.
#[derive(Clone, Copy, Debug, Default, PartialEq, Eq)]
pub struct PassResult {
    pub finished: usize,
    pub failed: usize,
}

/// Everything a job needs, shared across tasks.
#[derive(Clone)]
pub struct Runner {
    pub pool: MySqlPool,
    pub config: Arc<IngestConfig>,
    pub connector: Arc<dyn Connector>,
    pub hooks: Arc<dyn StoreHooks>,
    pub stop: Option<Shutdown>,
    pub job_timeout: Duration,
}

impl Runner {
    /// Runs each group's jobs in order, with at most `parallel` jobs at once across groups. A
    /// semaphore's fair queue interleaves the groups. A failure doesn't stop other groups or a
    /// group's later jobs, and contiguous ranges after a failure refuse by themselves.
    pub async fn run(&self, plan: Vec<GroupJobs>, parallel: usize) -> PassResult {
        let permits = Arc::new(Semaphore::new(parallel.max(1)));
        let mut tasks = JoinSet::new();
        for group in plan {
            let (runner, permits) = (self.clone(), permits.clone());
            tasks.spawn(async move {
                let mut result = PassResult::default();
                for job in group.jobs {
                    let Ok(_permit) = permits.acquire().await else {
                        break;
                    };
                    if runner.stop.as_ref().is_some_and(Shutdown::requested) {
                        break;
                    }
                    match runner.run_job(&job).await {
                        Ok(()) => result.finished += 1,
                        Err(failure) => {
                            result.failed += 1;
                            error!(?job, error = format!("{failure:#}"), "header job failed");
                        }
                    }
                }
                result
            });
        }
        let mut total = PassResult::default();
        while let Some(joined) = tasks.join_next().await {
            match joined {
                Ok(result) => {
                    total.finished += result.finished;
                    total.failed += result.failed;
                }
                Err(failure) => {
                    total.failed += 1;
                    error!(error = %failure, "a header task panicked");
                }
            }
        }
        total
    }

    async fn run_job(&self, job: &Job) -> Result<()> {
        tokio::time::timeout(self.job_timeout, self.run_job_inner(job))
            .await
            .context("the header job timed out")?
    }

    async fn run_job_inner(&self, job: &Job) -> Result<()> {
        let mut connection = self.connector.connect().await?;
        let mut ingest = Ingest::new(
            &self.pool,
            &self.config,
            self.hooks.as_ref(),
            connection.as_mut(),
            self.stop.clone(),
        );
        match job {
            Job::UpdateHeaders { group, max } => {
                let Some(found) = groups::by_name(&self.pool, group).await? else {
                    anyhow::bail!("Group not found: {group}");
                };
                ingest.update_group(&found, *max).await
            }
            Job::Range(range) => {
                let request = RangeRequest {
                    mode: range.mode,
                    group: range.group.clone(),
                    first: range.first,
                    last: range.last,
                    contiguous: range.contiguous,
                    repair: range.repair,
                };
                get_range(&mut ingest, &request).await
            }
            Job::Backfill { group, target } => {
                ingest.backfill_all(Some(group), *target, Order::Name).await
            }
        }
    }

    /// Refreshes `short_groups` from the server's group list, which the safe passes read.
    pub async fn update_short_groups(&self) -> Result<usize> {
        let mut connection = self.connector.connect().await?;
        let listed = connection
            .list_groups()
            .await
            .context("Failed to get the group list from the NNTP server")?;
        groups::replace_short_groups(&self.pool, &listed).await
    }

    /// `multiprocessing:safe binaries`.
    pub async fn safe_binaries(
        &self,
        redis: Option<redis::aio::ConnectionManager>,
    ) -> Result<PassResult> {
        self.update_short_groups().await?;
        let found: Vec<SafeGroup> = sqlx::query_as(
            "SELECT g.id, g.name AS groupname, g.last_record AS our_last, a.last_record AS their_last
             FROM usenet_groups g INNER JOIN short_groups a ON g.active = 1 AND g.name = a.name
             ORDER BY g.id",
        )
        .fetch_all(&self.pool)
        .await?;
        if found.is_empty() {
            return Ok(PassResult::default());
        }
        let last_group = match &redis {
            Some(redis) => {
                let mut redis = redis.clone();
                redis
                    .get::<_, Option<u32>>(ROTATION_KEY)
                    .await
                    .unwrap_or(None)
                    .unwrap_or(0)
            }
            None => 0,
        };
        let max_headers = match self.config.max_headers_per_iteration {
            0 => 1_000_000,
            max => max,
        };
        let mut max_messages = self.config.message_buffer;
        if max_messages < 1 {
            warn!("max_messages is invalid, using the default of 20000");
            max_messages = 20_000;
        }
        let plan = plan_safe_binaries(&found, last_group, max_messages, max_headers);
        let result = self.run(plan, self.config.binary_threads).await;
        if let (Some(redis), Some(last)) = (redis, found.iter().map(|group| group.id).max()) {
            let mut redis = redis;
            let next = if last_group >= last { 0 } else { last };
            if let Err(failure) = redis.set::<_, _, ()>(ROTATION_KEY, next).await {
                warn!(error = %failure, "couldn't record the group rotation");
            }
        }
        Ok(result)
    }

    /// `multiprocessing:safe backfill`.
    pub async fn safe_backfill(&self) -> Result<PassResult> {
        self.update_short_groups().await?;
        let config = &self.config;
        let days: i64 = match config.backfill_days_mode {
            1 => -1,
            2 => (chrono::Utc::now().date_naive() - config.safe_backfill_date).num_days(),
            _ => 0,
        };
        let interval = if days < 0 {
            "g.backfill_target".to_owned()
        } else {
            days.to_string()
        };
        let order = safe_backfill_order(config.backfill_order);
        let sql = format!(
            "SELECT g.name, CAST(g.first_record AS SIGNED), CAST(MAX(a.first_record) AS SIGNED)
             FROM usenet_groups g INNER JOIN short_groups a ON g.name = a.name
             WHERE g.first_record IS NOT NULL AND g.first_record_postdate IS NOT NULL
             AND g.backfill = 1 AND (NOW() - INTERVAL {interval} DAY) < g.first_record_postdate
             GROUP BY a.name, a.last_record, g.name, g.first_record {order} LIMIT 1"
        );
        let found: Option<(String, i64, i64)> = sqlx::query_as(sqlx::AssertSqlSafe(sql))
            .fetch_optional(&self.pool)
            .await?;
        let Some((name, our_first, their_first)) = found else {
            return Ok(PassResult::default());
        };
        let jobs = plan_safe_backfill(
            &name,
            our_first,
            their_first,
            i64::try_from(config.backfill_quantity).unwrap_or(i64::MAX),
            i64::try_from(config.backfill_threads).unwrap_or(1),
            i64::try_from(config.message_buffer).unwrap_or(i64::MAX),
        );
        // These ranges run side by side, each deferring to part repair if it can't run.
        let plan = jobs
            .into_iter()
            .enumerate()
            .map(|(index, job)| GroupJobs {
                group: u32::try_from(index).unwrap_or(0),
                jobs: vec![job],
            })
            .collect();
        Ok(self.run(plan, config.backfill_threads).await)
    }

    /// `multiprocessing:backfill`: every backfill-enabled group.
    pub async fn backfill(&self, limit: Option<u64>) -> Result<PassResult> {
        // PHP read no limit, or one of zero, as date mode.
        let limit = limit.filter(|count| *count > 0);
        let names: Vec<(u32, String)> =
            sqlx::query_as("SELECT id, name FROM usenet_groups WHERE backfill = 1")
                .fetch_all(&self.pool)
                .await?;
        let target = limit.map_or(Target::Date, Target::Articles);
        let plan = names
            .into_iter()
            .map(|(group, name)| GroupJobs {
                group,
                jobs: vec![Job::Backfill {
                    group: name,
                    target,
                }],
            })
            .collect();
        Ok(self.run(plan, self.config.backfill_threads).await)
    }
}

/// The settings a role reads each pass.
#[derive(Clone, Copy, Debug, FromRow)]
pub struct RoleSettings {
    pub binaries_enabled: bool,
    pub binaries_timer: u32,
    pub backfill_mode: u8,
    pub backfill_timer: u32,
    pub progressive_backfill: bool,
    pub collections_kill_threshold: u32,
}

pub async fn role_settings(pool: &MySqlPool) -> Result<RoleSettings> {
    Ok(sqlx::query_as(
        "SELECT binaries_enabled, binaries_timer, backfill_mode, backfill_timer,
                progressive_backfill, collections_kill_threshold
         FROM tmux_configurations WHERE id = 1",
    )
    .fetch_one(pool)
    .await?)
}

/// How long backfill rests. With progressive backfill, a large collections table slows it down.
pub fn backfill_sleep(settings: &RoleSettings, collections: i64) -> u64 {
    let base = u64::from(settings.backfill_timer);
    let scaled = u64::try_from(collections / 500).unwrap_or(0);
    if settings.progressive_backfill && scaled > base {
        scaled
    } else {
        base
    }
}

/// Builds a runner for a role or a command.
pub async fn runner(ctx: &Ctx, stop: Option<Shutdown>) -> Result<Runner> {
    let config = Arc::new(IngestConfig::load(&ctx.db).await?);
    let seconds: u64 = parsed_or("NN_MULTIPROCESSING_MAX_CHILD_TIME", 1800)?;
    let hooks = Arc::new(VerifyHooks(Verify::new(
        ctx.db.clone(),
        Cache::new(ctx.redis.clone(), "nntmux:"),
        config.verification.clone(),
    )));
    Ok(Runner {
        pool: ctx.db.clone(),
        config,
        connector: crate::connector(&ctx.db).await?,
        hooks,
        stop,
        job_timeout: Duration::from_secs(seconds),
    })
}

/// `nntmux-worker ingest`: safe update passes, resting `binaries_timer` between them.
pub async fn run_update_role(ctx: Ctx, once: bool, stop: Shutdown) -> Result<()> {
    while !stop.requested() {
        let outcome = update_pass(&ctx, &stop).await;
        let rest = rest_after(outcome, once)?;
        if once {
            return Ok(());
        }
        stop.sleep(rest).await;
    }
    Ok(())
}

async fn update_pass(ctx: &Ctx, stop: &Shutdown) -> Result<Duration> {
    let Some(_busy) = busy::enter(ctx, "ingest").await? else {
        return Ok(PAUSED_REST);
    };
    let settings = role_settings(&ctx.db).await?;
    if settings.binaries_enabled {
        let runner = runner(ctx, Some(stop.clone())).await?;
        let result = runner.safe_binaries(Some(ctx.redis.clone())).await?;
        info!(
            finished = result.finished,
            failed = result.failed,
            "update pass done"
        );
    }
    Ok(Duration::from_secs(
        u64::from(settings.binaries_timer).max(1),
    ))
}

/// `nntmux-worker backfill`: the configured backfill mode, held while collections are over the
/// kill threshold, as they back up when releases can't keep up.
pub async fn run_backfill_role(ctx: Ctx, once: bool, stop: Shutdown) -> Result<()> {
    while !stop.requested() {
        let outcome = backfill_pass(&ctx, &stop).await;
        let rest = rest_after(outcome, once)?;
        if once {
            return Ok(());
        }
        stop.sleep(rest).await;
    }
    Ok(())
}

async fn backfill_pass(ctx: &Ctx, stop: &Shutdown) -> Result<Duration> {
    let Some(_busy) = busy::enter(ctx, "backfill").await? else {
        return Ok(PAUSED_REST);
    };
    let settings = role_settings(&ctx.db).await?;
    let collections: i64 = sqlx::query_scalar("SELECT COUNT(*) FROM collections")
        .fetch_one(&ctx.db)
        .await?;
    let limit = i64::from(settings.collections_kill_threshold);
    let held = limit > 0 && limit < collections;
    if !held && matches!(settings.backfill_mode, 1 | 4) {
        let runner = runner(ctx, Some(stop.clone())).await?;
        let result = if settings.backfill_mode == 1 {
            runner.backfill(None).await?
        } else {
            runner.safe_backfill().await?
        };
        info!(
            finished = result.finished,
            failed = result.failed,
            "backfill pass done"
        );
    }
    Ok(Duration::from_secs(
        backfill_sleep(&settings, collections).max(1),
    ))
}

/// A failed pass doesn't end a role, as a transient server failure didn't end a tmux pane. A
/// `--once` run reports it.
fn rest_after(outcome: Result<Duration>, once: bool) -> Result<Duration> {
    match outcome {
        Ok(rest) => Ok(rest),
        Err(failure) if once => Err(failure),
        Err(failure) => {
            error!(error = format!("{failure:#}"), "header pass failed");
            Ok(FAILED_REST)
        }
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    fn group(id: u32, our_last: u64, their_last: u64) -> SafeGroup {
        SafeGroup {
            id,
            groupname: format!("g{id}"),
            our_last,
            their_last,
        }
    }

    #[test]
    fn a_group_close_to_caught_up_takes_one_visit() {
        let plan = plan_safe_binaries(&[group(1, 1000, 30_000)], 0, 20000, 1_000_000);
        assert_eq!(
            plan[0].jobs,
            [Job::UpdateHeaders {
                group: "g1".to_owned(),
                max: 20000
            }]
        );
        let plan = plan_safe_binaries(&[group(1, 0, 500_000)], 0, 20000, 1_000_000);
        assert!(matches!(plan[0].jobs[0], Job::UpdateHeaders { .. }));
    }

    #[test]
    fn a_group_far_behind_splits_into_contiguous_ranges() {
        let plan = plan_safe_binaries(&[group(1, 1000, 100_000)], 0, 20000, 1_000_000);
        let ranges: Vec<(u64, u64, bool, bool)> = plan[0]
            .jobs
            .iter()
            .map(|job| match job {
                Job::Range(range) => (range.first, range.last, range.contiguous, range.repair),
                other => panic!("{other:?}"),
            })
            .collect();
        assert_eq!(
            ranges,
            [
                (1001, 21000, true, true),
                (21001, 41000, true, false),
                (41001, 61000, true, false),
                (61001, 80000, true, false),
            ]
        );
    }

    #[test]
    fn caps_a_pass_at_max_headers() {
        let plan = plan_safe_binaries(&[group(1, 1000, 1_000_000)], 0, 20000, 30000);
        let Job::Range(last) = plan[0].jobs.last().unwrap() else {
            panic!();
        };
        assert_eq!((plan[0].jobs.len(), last.last), (2, 31000));
    }

    #[test]
    fn rotates_past_the_last_group() {
        let plan = plan_safe_binaries(
            &[group(1, 0, 0), group(2, 0, 0), group(3, 0, 0)],
            2,
            20000,
            100,
        );
        let order: Vec<u32> = plan.iter().map(|group| group.group).collect();
        assert_eq!(order, [3, 1, 2]);
    }

    #[test]
    fn safe_backfill_takes_ranges_below_the_first_record() {
        let jobs = plan_safe_backfill("g", 1000, 1, 300, 1, 100);
        assert_eq!(jobs.len(), 3);
        let Job::Range(first) = &jobs[0] else {
            panic!();
        };
        assert_eq!(
            (first.first, first.last, first.contiguous),
            (900, 999, false)
        );
        assert!(plan_safe_backfill("g", 1000, 1000, 300, 1, 100).is_empty());
        // Fewer articles remain than the budget.
        assert_eq!(plan_safe_backfill("g", 1000, 950, 300, 1, 100).len(), 1);
        // A chunk larger than what's left stops at the server's first article.
        let jobs = plan_safe_backfill("g", 10000, 1, 100_000, 1, 20000);
        let Job::Range(only) = &jobs[0] else {
            panic!();
        };
        assert_eq!((jobs.len(), only.first, only.last), (1, 1, 9999));
        let jobs = plan_safe_backfill("g", 10000, 9990, 100_000, 1, 20000);
        let Job::Range(only) = &jobs[0] else {
            panic!();
        };
        assert_eq!((jobs.len(), only.first, only.last), (1, 9990, 9999));
    }

    #[test]
    fn progressive_backfill_rests_longer_on_a_large_table() {
        let settings = RoleSettings {
            binaries_enabled: true,
            binaries_timer: 60,
            backfill_mode: 1,
            backfill_timer: 600,
            progressive_backfill: true,
            collections_kill_threshold: 0,
        };
        assert_eq!(backfill_sleep(&settings, 1000), 600);
        assert_eq!(backfill_sleep(&settings, 500_000), 1000);
        let plain = RoleSettings {
            progressive_backfill: false,
            ..settings
        };
        assert_eq!(backfill_sleep(&plain, 500_000), 600);
    }
}
