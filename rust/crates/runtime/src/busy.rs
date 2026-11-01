//! Which roles are holding work right now, so a drain can wait for them. Each unit of work holds a
//! Redis key that expires shortly after its owner stops refreshing it, so a crashed worker can't
//! block a drain for long. Work that can't be marked isn't admitted, because a drain couldn't see
//! it: a Redis outage holds new work back until Redis returns. Once marked, a failed refresh only
//! warns, so running work is never interrupted.

use std::sync::atomic::{AtomicU64, Ordering};
use std::time::Duration;

use anyhow::{Context, Result};
use redis::AsyncCommands;
use redis::aio::ConnectionManager;
use tokio::task::JoinHandle;
use tracing::warn;

use crate::Ctx;

const TTL_SECONDS: u64 = 60;
const REFRESH: Duration = Duration::from_secs(20);
const REDIS_LIMIT: Duration = Duration::from_secs(2);

static NEXT: AtomicU64 = AtomicU64::new(0);

fn prefix(ctx: &Ctx) -> String {
    format!("nntmux:busy:{}:", ctx.config.database.database)
}

/// Held while a unit of work runs. Dropping it clears the mark.
pub struct Busy {
    key: String,
    redis: ConnectionManager,
    refresh: JoinHandle<()>,
}

/// The gate every unit of work passes. Marks `role` busy, then reads the pause flag, and returns
/// `None` if processing is paused. A drain sets the flag before it scans the marks, so either the
/// drain sees this mark or this read sees the flag: work can't slip past a finished drain. Roles
/// that don't run through [`crate::run_slots`] hold the result for each unit of work.
pub async fn enter(ctx: &Ctx, role: &str) -> Result<Option<Busy>> {
    let busy = mark(ctx, role).await?;
    Ok((!ctx.paused().await?).then_some(busy))
}

/// Marks `role` busy until the result is dropped, without checking the pause flag. Fails if the mark
/// can't be written.
pub async fn mark(ctx: &Ctx, role: &str) -> Result<Busy> {
    let key = format!(
        "{}{role}:{}:{}",
        prefix(ctx),
        std::process::id(),
        NEXT.fetch_add(1, Ordering::Relaxed)
    );
    let mut redis = ctx.redis.clone();
    set(&mut redis, &key).await?;
    let refresh = tokio::spawn({
        let (mut redis, key) = (redis.clone(), key.clone());
        async move {
            loop {
                tokio::time::sleep(REFRESH).await;
                if let Err(failure) = set(&mut redis, &key).await {
                    warn!(
                        error = format!("{failure:#}"),
                        "unable to refresh a busy mark"
                    );
                }
            }
        }
    });
    Ok(Busy {
        key,
        redis,
        refresh,
    })
}

async fn set(redis: &mut ConnectionManager, key: &str) -> Result<()> {
    let write = redis.set_ex::<_, _, ()>(key, 1, TTL_SECONDS);
    tokio::time::timeout(REDIS_LIMIT, write)
        .await
        .context("timed out marking work as busy")?
        .context("marking work as busy")
}

impl Drop for Busy {
    fn drop(&mut self) {
        self.refresh.abort();
        let (mut redis, key) = (self.redis.clone(), std::mem::take(&mut self.key));
        if let Ok(runtime) = tokio::runtime::Handle::try_current() {
            runtime.spawn(async move {
                let clear = redis.del::<_, ()>(&key);
                let _ = tokio::time::timeout(REDIS_LIMIT, clear).await;
            });
        }
    }
}

/// The roles with work in flight, sorted and without repeats.
pub async fn active_roles(ctx: &Ctx) -> Result<Vec<String>> {
    let prefix = prefix(ctx);
    let mut redis = ctx.redis.clone();
    let mut roles = Vec::new();
    let mut cursor = 0u64;
    loop {
        let (next, keys): (u64, Vec<String>) = redis::cmd("SCAN")
            .arg(cursor)
            .arg("MATCH")
            .arg(format!("{prefix}*"))
            .arg("COUNT")
            .arg(1000)
            .query_async(&mut redis)
            .await?;
        roles.extend(keys.iter().filter_map(|key| {
            let rest = key.strip_prefix(&prefix)?;
            Some(rest.split(':').next()?.to_owned())
        }));
        if next == 0 {
            break;
        }
        cursor = next;
    }
    roles.sort();
    roles.dedup();
    Ok(roles)
}
