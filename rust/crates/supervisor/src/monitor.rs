//! What the tmux monitor kept doing beside its panes: giving waiting collections back the time lost
//! to an outage, and marking ingestion active so the next start can measure one.

use std::time::Duration;

use anyhow::Result;
use chrono::Utc;
use nntmux_core::parsed_or;
use nntmux_runtime::{Ctx, Shutdown, downtime};
use redis::AsyncCommands;
use tracing::{info, warn};

/// When the monitor last ran, for `nntmux-ctl ops health-check`.
pub const HEARTBEAT_KEY: &str = "nntmux:supervisor:heartbeat";

const DEFAULT_DELAY_HOURS: i64 = 2;

async fn collection_delay_hours(ctx: &Ctx) -> Result<i64> {
    let delay: Option<u32> = sqlx::query_scalar(
        "SELECT collection_delay_hours FROM ingestion_configurations WHERE id = 1",
    )
    .fetch_optional(&ctx.db)
    .await?;
    Ok(delay.map_or(DEFAULT_DELAY_HOURS, i64::from))
}

/// Runs before anything is scheduled. While processing is paused nothing is consuming the outage
/// yet: the first activity recorded after the resume compensates for it.
pub async fn compensate_on_start(ctx: &Ctx, stop: &Shutdown) -> Result<()> {
    if ctx.paused().await? {
        return Ok(());
    }
    let delay = collection_delay_hours(ctx).await?;
    tokio::select! {
        moved = downtime::compensate(ctx, delay) => {
            let moved = moved?;
            if moved > 0 {
                info!(moved, "gave waiting collections back the time lost while the indexer was down");
            }
        }
        () = stop.token().cancelled_owned() => {}
    }
    Ok(())
}

pub async fn run(ctx: Ctx, stop: Shutdown) {
    let delay = parsed_or("TMUX_MONITOR_DELAY", 10u64).unwrap_or(10).max(1);
    while !stop.requested() {
        beat(&ctx, &stop).await;
        stop.sleep(Duration::from_secs(delay)).await;
    }
}

/// Monitoring must never stop the supervisor, so failures only warn. A pause stops the activity
/// marker, so the paused time counts as downtime, as it did when the monitor exited on the flag.
async fn beat(ctx: &Ctx, stop: &Shutdown) {
    let mut redis = ctx.redis.clone();
    let heartbeat: redis::RedisResult<()> = redis.set(HEARTBEAT_KEY, Utc::now().timestamp()).await;
    if let Err(failure) = heartbeat {
        warn!(error = %failure, "unable to record the supervisor heartbeat");
    }
    match ctx.paused().await {
        Ok(true) => return,
        Ok(false) => {}
        Err(failure) => {
            warn!(
                error = format!("{failure:#}"),
                "unable to read the pause flag"
            );
            return;
        }
    }
    tokio::select! {
        recorded = downtime::record_activity(ctx) => {
            if let Err(failure) = recorded {
                warn!(error = format!("{failure:#}"), "unable to record ingestion activity");
            }
        }
        () = stop.token().cancelled_owned() => {}
    }
}
