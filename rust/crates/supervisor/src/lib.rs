//! Scheduled tasks and worker supervision: the scheduler and tmux replacement.
//!
//! Owner: ws-ops. See `rust/WORKSTREAMS.md`.

pub mod cadence;
pub mod engine;
pub mod leader;
pub mod monitor;
pub mod schedule;

use std::time::Duration;

use anyhow::{Result, bail};
use chrono::{DateTime, Duration as Minutes, DurationRound, Utc};
use nntmux_runtime::{Ctx, RoleArgs, Shutdown};
use tracing::{info, warn};

use crate::engine::Engine;
use crate::leader::Leader;

/// A stall this short still runs the minutes it missed. After a longer gap, such as a suspended
/// host, the schedule picks up at the current minute, as a restarted Laravel scheduler did.
const MAX_CATCH_UP_MINUTES: i64 = 5;

/// How long a stopping supervisor waits for running tasks.
const STOP_LIMIT: Duration = Duration::from_secs(30);

pub async fn run(ctx: Ctx, args: RoleArgs, stop: Shutdown) -> Result<()> {
    let Some(mut leader) = Leader::acquire(&ctx.db, &stop).await? else {
        return Ok(());
    };
    let mut engine = Engine::new(ctx.clone(), schedule::tasks());
    if args.once {
        monitor::compensate_on_start(&ctx, &stop).await?;
        if !stop.requested() {
            engine.tick(minute_of(Utc::now()));
            engine.drain().await;
        }
        return Ok(());
    }
    let monitoring = tokio::spawn(monitor::run(ctx, stop.clone()));
    let lost = schedule_loop(&mut engine, &mut leader, &stop).await;
    info!("waiting for running tasks");
    engine.drain_within(STOP_LIMIT).await;
    stop.request();
    monitoring.await?;
    if lost {
        bail!("lost the supervisor lock");
    }
    Ok(())
}

/// True if the lock was lost; a stop returns false. The minute the supervisor starts in doesn't
/// run, so a restart can't run its tasks twice. Laravel's scheduler only ran on the minute too.
async fn schedule_loop(engine: &mut Engine<Ctx>, leader: &mut Leader, stop: &Shutdown) -> bool {
    let mut last = minute_of(Utc::now());
    loop {
        stop.sleep(until_next_minute(Utc::now())).await;
        if stop.requested() {
            return false;
        }
        let held = tokio::select! {
            held = leader.still_held() => held,
            () = stop.token().cancelled_owned() => return false,
        };
        if !held {
            warn!("lost the supervisor lock; stopping so a new start can take it back");
            return true;
        }
        let now = minute_of(Utc::now());
        for minute in minutes_to_run(last, now) {
            engine.tick(minute);
        }
        last = last.max(now);
    }
}

fn minute_of(at: DateTime<Utc>) -> DateTime<Utc> {
    at.duration_trunc(Minutes::minutes(1)).unwrap_or(at)
}

fn until_next_minute(now: DateTime<Utc>) -> Duration {
    let next = minute_of(now) + Minutes::minutes(1);
    (next - now).to_std().unwrap_or(Duration::from_secs(1))
}

/// The minutes after `last`, up to `now`. A clock that stepped back runs nothing until it catches
/// up, so a minute never runs twice.
fn minutes_to_run(last: DateTime<Utc>, now: DateTime<Utc>) -> Vec<DateTime<Utc>> {
    let missed = (now - last).num_minutes();
    if missed <= 0 {
        return Vec::new();
    }
    if missed > MAX_CATCH_UP_MINUTES {
        return vec![now];
    }
    (1..=missed)
        .map(|step| last + Minutes::minutes(step))
        .collect()
}

#[cfg(test)]
mod tests {
    use super::*;
    use chrono::TimeZone;

    fn at(minute: u32, second: u32) -> DateTime<Utc> {
        Utc.with_ymd_and_hms(2026, 11, 2, 3, minute, second)
            .single()
            .expect("valid date")
    }

    #[test]
    fn sleeps_to_the_next_minute() {
        assert_eq!(until_next_minute(at(1, 45)), Duration::from_secs(15));
        assert_eq!(until_next_minute(at(1, 0)), Duration::from_secs(60));
    }

    #[test]
    fn catches_up_short_stalls_only() {
        assert_eq!(minutes_to_run(at(1, 0), at(2, 0)), [at(2, 0)]);
        assert_eq!(minutes_to_run(at(1, 0), at(3, 0)), [at(2, 0), at(3, 0)]);
        assert_eq!(minutes_to_run(at(1, 0), at(30, 0)), [at(30, 0)]);
        assert!(minutes_to_run(at(5, 0), at(5, 0)).is_empty());
        assert!(minutes_to_run(at(5, 0), at(4, 0)).is_empty());
    }
}
