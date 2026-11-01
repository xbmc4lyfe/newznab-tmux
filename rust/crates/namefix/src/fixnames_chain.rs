//! What the tmux monitor's fix-names pane ran each cycle (`TmuxTaskRunner::runFixNamesTask` and
//! `fixNamesCommands`), as a plan the `fixnames` role executes.
//!
//! The odd levels look at the past six hours and run whenever the release table has pending
//! rename work. The even levels look at everything and run at most once per interval. The
//! PAR2 levels, 7 and 8, follow the database-only levels and stay out of the chain when the
//! scheduler owns them (`TMUX_FIX_NAMES_PAR2_SCHEDULED`). Between cycles the pane slept
//! `fix_timer` seconds, which here is a Redis key, so any number of role processes share one
//! cadence and none polls the release table more often than the pane did.

use anyhow::Result;
use nntmux_runtime::Ctx;
use redis::AsyncCommands;
use sqlx::MySqlPool;

use crate::par2_check::env_bool;
use crate::par2_lock::RedisLock;
use crate::queries::{self, FixNamesConfig, Source};

const RUNNING_KEY: &str = "tmux:fix-names:chain:running";
const REST_KEY: &str = "tmux:fix-names:chain:rest";
const FULL_BACKLOG_KEY: &str = "tmux:fix-names:full-backlog";

fn key(prefix: &str, name: &str) -> String {
    format!("{prefix}{name}")
}
/// A chain that dies without releasing its claim is retried after this long. A running chain
/// keeps renewing it.
const RUNNING_SECONDS: u64 = 120;
const RUNNING_RENEW_SECONDS: u64 = 30;
/// How long to wait before looking for work again when there was none.
const IDLE_REST_SECONDS: u64 = 30;
const DEFAULT_FIX_TIMER: i64 = 300;

/// Odd levels: the past six hours.
const RECENT_LEVELS: [u32; 8] = [3, 5, 9, 11, 13, 15, 17, 19];
/// Even levels: everything.
const BACKLOG_LEVELS: [u32; 8] = [4, 6, 10, 12, 14, 16, 18, 20];

/// One cycle's levels, in the order they run.
#[derive(Debug, PartialEq, Eq)]
pub struct Plan {
    pub levels: Vec<u32>,
}

/// `fixNamesCommands`, minus the PreDB pass, which the role runs on its own cadence.
pub fn plan_levels(
    rename_passes: bool,
    full_backlog: bool,
    par2_scheduled: bool,
    full_backlog_enabled: bool,
) -> Vec<u32> {
    let mut levels = Vec::new();
    if rename_passes {
        levels.extend(RECENT_LEVELS);
    }
    if full_backlog {
        levels.extend(BACKLOG_LEVELS);
    }
    if !par2_scheduled {
        if rename_passes {
            levels.push(7);
        }
        if full_backlog || (rename_passes && full_backlog_enabled) {
            levels.push(8);
        }
    }
    levels
}

/// `NameFixingQueryService::pendingWorkCondition`: releases with a rename source still to try.
pub fn pending_work_condition(config: &FixNamesConfig) -> String {
    let mut sources = Vec::new();
    for source in [
        Source::Nfo,
        Source::Files,
        Source::Srr,
        Source::Crc,
        Source::Uid,
        Source::Hash,
        Source::Xxx,
        Source::MediaMovie,
    ] {
        let mut condition = source.exists().to_owned();
        if source == Source::Hash {
            condition.push_str(
                " AND NOT EXISTS (SELECT 1 FROM par2_hash_retry hr WHERE hr.releases_id = r.id \
                 AND HEX(hr.release_guid) = HEX(r.guid) AND hr.retry_at > CURRENT_TIMESTAMP)",
            );
        }
        sources.push(format!(
            "(r.{} = 0 AND ({condition}))",
            source.status_column()
        ));
    }
    let category = if config.category == "all" {
        String::new()
    } else {
        format!(" AND r.categories_id IN ({})", queries::others_group())
    };
    let settled = "r.late_article_candidate_id IS NULL";
    let mut condition = format!(
        "r.predb_id = 0 AND (r.isrenamed = 0 OR r.categories_id IN ({}, {})){category} \
         AND (CASE WHEN r.proc_par2 = 0 AND ({}) AND {settled} THEN 1 WHEN {} THEN 1 ELSE 0 END = 1) \
         AND {settled}",
        nntmux_categorize::cat::OTHER_MISC,
        nntmux_categorize::cat::OTHER_HASHED,
        Source::Par2.exists(),
        sources.join(" OR ")
    );
    if !config.full_backlog {
        condition.push_str(" AND r.adddate > CURRENT_TIMESTAMP - INTERVAL 6 HOUR");
    }
    condition
}

/// Whether any release has pending rename work.
pub async fn has_pending_work(db: &MySqlPool, config: &FixNamesConfig) -> Result<bool> {
    let sql = format!(
        "SELECT EXISTS(SELECT 1 FROM releases r WHERE {})",
        pending_work_condition(config)
    );
    let found: i64 = sqlx::query_scalar(sqlx::AssertSqlSafe(sql))
        .fetch_one(db)
        .await?;
    Ok(found == 1)
}

/// `(fix_names_enabled, fix_timer)` from the tmux settings.
async fn settings(db: &MySqlPool) -> Result<(bool, i64)> {
    let row: Option<(i64, i64)> = sqlx::query_as(
        "SELECT CAST(fix_names_enabled AS SIGNED), CAST(fix_timer AS SIGNED) FROM tmux_configurations LIMIT 1",
    )
    .fetch_optional(db)
    .await?;
    Ok(row.map_or((false, DEFAULT_FIX_TIMER), |(enabled, timer)| {
        (enabled == 1, timer)
    }))
}

/// `claimFullBacklogSlot`: at most one historical pass per interval, across every process.
async fn claim_full_backlog(ctx: &Ctx, config: &FixNamesConfig, prefix: &str) -> bool {
    if !config.full_backlog {
        return false;
    }
    let minutes = std::env::var("TMUX_FIX_NAMES_FULL_INTERVAL")
        .ok()
        .and_then(|v| v.trim().parse::<i64>().ok())
        .unwrap_or(60);
    if minutes <= 0 {
        return true;
    }
    let mut redis = ctx.redis.clone();
    let claimed: redis::RedisResult<Option<String>> = redis::cmd("SET")
        .arg(key(prefix, FULL_BACKLOG_KEY))
        .arg("1")
        .arg("NX")
        .arg("EX")
        .arg(minutes * 60)
        .query_async(&mut redis)
        .await;
    match claimed {
        Ok(claimed) => claimed.is_some(),
        // The pass is optional: skip it this cycle rather than stopping the role.
        Err(error) => {
            tracing::warn!(%error, "Skipping the fix-names full-backlog pass; cache unavailable");
            false
        }
    }
}

/// A claimed cycle. Finishing it starts the pane's rest.
pub struct Claimed {
    pub levels: Vec<u32>,
    ctx: Ctx,
    prefix: String,
    rest_seconds: u64,
    lock: RedisLock,
}

/// Renews a claim until dropped, including when a panic unwinds the chain.
pub struct Heartbeat(tokio::task::JoinHandle<()>);

impl Drop for Heartbeat {
    fn drop(&mut self) {
        self.0.abort();
    }
}

impl Claimed {
    pub fn heartbeat(&self) -> Heartbeat {
        let lock = self.lock.clone();
        Heartbeat(tokio::spawn(async move {
            loop {
                tokio::time::sleep(std::time::Duration::from_secs(RUNNING_RENEW_SECONDS)).await;
                if !lock.refresh().await {
                    tracing::warn!("Could not renew the fix-names chain claim");
                }
            }
        }))
    }

    pub async fn finish(self) {
        let mut redis = self.ctx.redis.clone();
        let rested: redis::RedisResult<()> = redis
            .set_ex(key(&self.prefix, REST_KEY), "1", self.rest_seconds.max(1))
            .await;
        if let Err(error) = rested {
            tracing::warn!(%error, "Could not record the fix-names rest");
        }
        if let Err(error) = self.lock.release().await {
            tracing::warn!(%error, "Could not release the fix-names chain claim");
        }
    }
}

/// Claims the next cycle if the pane would run one now: enabled, not resting, not already
/// running, and with something to do. Returns nothing otherwise.
pub async fn claim(ctx: &Ctx, config: &FixNamesConfig, prefix: &str) -> Result<Option<Claimed>> {
    let (enabled, timer) = settings(&ctx.db).await?;
    if !enabled {
        return Ok(None);
    }
    let mut redis = ctx.redis.clone();
    let resting: bool = redis.exists(key(prefix, REST_KEY)).await?;
    if resting {
        return Ok(None);
    }
    let lock = RedisLock::new(ctx.redis.clone(), key(prefix, RUNNING_KEY), RUNNING_SECONDS);
    if !lock.acquire().await? {
        return Ok(None);
    }
    let claimed = Claimed {
        levels: Vec::new(),
        ctx: ctx.clone(),
        prefix: prefix.to_owned(),
        rest_seconds: IDLE_REST_SECONDS,
        lock: lock.clone(),
    };
    let levels = async {
        let work = has_pending_work(&ctx.db, config).await?;
        let full_backlog = claim_full_backlog(ctx, config, prefix).await;
        let par2_scheduled = env_bool("TMUX_FIX_NAMES_PAR2_SCHEDULED").unwrap_or(false);
        Ok::<_, anyhow::Error>(plan_levels(
            work,
            full_backlog,
            par2_scheduled,
            config.full_backlog,
        ))
    }
    .await;
    match levels {
        Ok(levels) if !levels.is_empty() => Ok(Some(Claimed {
            levels,
            rest_seconds: timer.max(1) as u64,
            ..claimed
        })),
        Ok(_) => {
            claimed.finish().await;
            Ok(None)
        }
        Err(error) => {
            // Don't hold the pane for a failed look; the next poll tries again.
            lock.release().await.ok();
            Err(error)
        }
    }
}

#[cfg(test)]
mod tests {
    use super::plan_levels;

    #[test]
    fn recent_levels_run_with_work_and_backlog_levels_with_the_interval() {
        assert_eq!(
            plan_levels(true, false, false, true),
            vec![3, 5, 9, 11, 13, 15, 17, 19, 7, 8]
        );
        assert_eq!(
            plan_levels(true, true, false, true),
            vec![
                3, 5, 9, 11, 13, 15, 17, 19, 4, 6, 10, 12, 14, 16, 18, 20, 7, 8
            ]
        );
        assert_eq!(
            plan_levels(false, true, false, true),
            vec![4, 6, 10, 12, 14, 16, 18, 20, 8]
        );
        assert!(plan_levels(false, false, false, true).is_empty());
    }

    #[test]
    fn par2_levels_follow_the_scheduler_and_the_backlog_switch() {
        assert_eq!(
            plan_levels(true, false, true, true),
            vec![3, 5, 9, 11, 13, 15, 17, 19]
        );
        // With the full backlog switched off, level 8 runs only on a backlog pass.
        assert_eq!(
            plan_levels(true, false, false, false),
            vec![3, 5, 9, 11, 13, 15, 17, 19, 7]
        );
        assert_eq!(plan_levels(true, true, false, false).last(), Some(&8));
    }
}
