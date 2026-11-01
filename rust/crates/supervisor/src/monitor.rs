//! What the tmux monitor kept doing beside its panes: giving waiting collections back the time lost
//! to an outage, and marking ingestion active so the next start can measure one.

use std::time::Duration;

use anyhow::Result;
use chrono::Utc;
use nntmux_core::parsed_or;
use nntmux_ops::runtime_state::heartbeat_key;
use nntmux_runtime::{Ctx, Shutdown, downtime};
use redis::AsyncCommands;
use tracing::{info, warn};

const DEFAULT_DELAY_HOURS: i64 = 2;

async fn collection_delay_hours(ctx: &Ctx) -> Result<i64> {
    let delay: Option<u32> = sqlx::query_scalar(
        "SELECT collection_delay_hours FROM ingestion_configurations WHERE id = 1",
    )
    .fetch_optional(&ctx.db)
    .await?;
    Ok(delay.map_or(DEFAULT_DELAY_HOURS, i64::from))
}

/// While processing is paused nothing is consuming the outage yet: the first activity recorded
/// after the resume compensates for it.
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

/// Runs beside the schedule, because the compensation lock can be held for a long time. A failed
/// compensation only warns: the next activity recorded claims the same outage. The heartbeat has its
/// own task, so a held lock can't make a live supervisor look dead.
pub async fn run(ctx: Ctx, stop: Shutdown) {
    let delay = Duration::from_secs(parsed_or("TMUX_MONITOR_DELAY", 10u64).unwrap_or(10).max(1));
    let heartbeat = tokio::spawn({
        let (ctx, stop) = (ctx.clone(), stop.clone());
        async move {
            while !stop.requested() {
                beat(&ctx).await;
                stop.sleep(delay).await;
            }
        }
    });
    if let Err(failure) = compensate_on_start(&ctx, &stop).await {
        warn!(
            error = format!("{failure:#}"),
            "unable to compensate on start"
        );
    }
    while !stop.requested() {
        mark_activity(&ctx, &stop).await;
        stop.sleep(delay).await;
    }
    let _ = heartbeat.await;
}

async fn beat(ctx: &Ctx) {
    let mut redis = ctx.redis.clone();
    let heartbeat: redis::RedisResult<()> =
        redis.set(heartbeat_key(ctx), Utc::now().timestamp()).await;
    if let Err(failure) = heartbeat {
        warn!(error = %failure, "unable to record the supervisor heartbeat");
    }
}

/// Monitoring must never stop the supervisor, so failures only warn. A pause stops the activity
/// marker, so the paused time counts as downtime, as it did when the monitor exited on the flag.
async fn mark_activity(ctx: &Ctx, stop: &Shutdown) {
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
