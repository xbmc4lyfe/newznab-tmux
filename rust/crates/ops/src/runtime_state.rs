//! The processing state operators and deploys control: the row every role checks before it claims
//! work, and the state saved across a drain.
//!
//! Workers now outlive a stop, because each role is its own container. So unlike `tmux:stop`, which
//! cleared the stop flag once its session was gone, a stop stays in force until the next start.

use std::time::{Duration, Instant};

use anyhow::{Context, Result, bail};
use chrono::Utc;
use nntmux_core::parsed_or;
use nntmux_runtime::{Ctx, Shutdown, busy};
use redis::AsyncCommands;
use serde_json::{Value, json};
use sqlx::{ConnectOptions, Connection, MySqlConnection};
use tracing::warn;

const POLL: Duration = Duration::from_millis(250);

/// When the supervisor's monitor last ran.
pub fn heartbeat_key(ctx: &Ctx) -> String {
    format!(
        "nntmux:supervisor:heartbeat:{}",
        ctx.config.database.database
    )
}

fn drain_key(ctx: &Ctx) -> String {
    format!("nntmux:drain:{}", ctx.config.database.database)
}

async fn flags(ctx: &Ctx) -> Result<(bool, bool)> {
    let row: Option<(bool, bool)> = sqlx::query_as(
        "SELECT tmux_running, stop_requested FROM processing_runtime_states WHERE id = 1",
    )
    .fetch_optional(&ctx.db)
    .await?;
    Ok(row.unwrap_or((false, false)))
}

async fn set_flags(ctx: &Ctx, running: bool, stopped: bool) -> Result<()> {
    sqlx::query(
        "INSERT INTO processing_runtime_states (id, tmux_running, stop_requested, created_at, updated_at) \
         VALUES (1, ?, ?, NOW(), NOW()) ON DUPLICATE KEY UPDATE tmux_running = VALUES(tmux_running), \
         stop_requested = VALUES(stop_requested), updated_at = VALUES(updated_at)",
    )
    .bind(running)
    .bind(stopped)
    .execute(&ctx.db)
    .await?;
    Ok(())
}

async fn saved_drain(ctx: &Ctx) -> Result<Option<Value>> {
    let mut redis = ctx.redis.clone();
    let saved: Option<String> = redis.get(drain_key(ctx)).await?;
    saved
        .map(|saved| serde_json::from_str(&saved).context("reading the saved drain state"))
        .transpose()
}

async fn save_drain(ctx: &Ctx, state: &Value) -> Result<()> {
    let mut redis = ctx.redis.clone();
    let _: () = redis.set(drain_key(ctx), state.to_string()).await?;
    Ok(())
}

async fn clear_drain(ctx: &Ctx) -> Result<()> {
    let mut redis = ctx.redis.clone();
    let _: () = redis.del(drain_key(ctx)).await?;
    Ok(())
}

/// Only one drain control runs at a time. A session lock, so a killed one can't leave it held.
async fn control_lock(ctx: &Ctx) -> Result<MySqlConnection> {
    let mut conn = ctx.db.connect_options().connect().await?;
    let got: Option<i64> =
        sqlx::query_scalar("SELECT GET_LOCK(CONCAT('nntmux-drain:', MD5(DATABASE())), 0)")
            .fetch_one(&mut conn)
            .await?;
    if got != Some(1) {
        bail!("Another drain control operation is active; processing state was preserved.");
    }
    Ok(conn)
}

fn check_token(token: Option<&str>) -> Result<()> {
    let valid = |token: &str| {
        (1..=128).contains(&token.len())
            && token
                .bytes()
                .all(|byte| byte.is_ascii_alphanumeric() || matches!(byte, b'_' | b'-'))
    };
    if token.is_some_and(|token| !valid(token)) {
        bail!("The operation token must contain 1-128 letters, digits, underscores or hyphens.");
    }
    Ok(())
}

pub async fn start(ctx: &Ctx) -> Result<()> {
    let lock = control_lock(ctx).await?;
    set_flags(ctx, true, false).await?;
    clear_drain(ctx).await?;
    let _ = lock.close().await;
    Ok(())
}

/// Returns the roles still finishing when the wait ended.
pub async fn stop(ctx: &Ctx, wait: bool, timeout: u64, signals: &Shutdown) -> Result<Vec<String>> {
    let lock = control_lock(ctx).await?;
    set_flags(ctx, false, true).await?;
    clear_drain(ctx).await?;
    let _ = lock.close().await;
    let deadline = Instant::now() + Duration::from_secs(timeout);
    loop {
        let active = busy::active_roles(ctx).await?;
        if active.is_empty() || signals.requested() || (!wait && Instant::now() >= deadline) {
            return Ok(active);
        }
        signals.sleep(POLL).await;
    }
}

/// Pauses new work and waits for active work. A failed drain puts the flags back as this call found
/// them, and keeps an earlier drain's saved state. A repeated drain with no token means an operator
/// took over, so no automated resume can end it.
pub async fn drain(ctx: &Ctx, timeout: u64, token: Option<&str>, signals: &Shutdown) -> Result<()> {
    check_token(token)?;
    let lock = control_lock(ctx).await?;
    let result = drain_locked(ctx, timeout, token, signals).await;
    let _ = lock.close().await;
    result
}

async fn drain_locked(
    ctx: &Ctx,
    timeout: u64,
    token: Option<&str>,
    signals: &Shutdown,
) -> Result<()> {
    let (running, stopped) = flags(ctx).await?;
    // State saved by a drain whose pause has since ended is stale: nothing is draining any more.
    let earlier = saved_drain(ctx).await?.filter(|_| stopped);
    match &earlier {
        None => {
            let state =
                json!({"running": running, "stopped": stopped, "token": token.unwrap_or_default()});
            save_drain(ctx, &state).await?;
        }
        Some(state) if token.is_none() => {
            let mut state = state.clone();
            state["token"] = json!("");
            save_drain(ctx, &state).await?;
        }
        Some(_) => {}
    }
    let waited = async {
        set_flags(ctx, false, true).await?;
        wait_for_workers(ctx, timeout, signals).await
    }
    .await;
    if waited.is_err() {
        let restored = async {
            set_flags(ctx, running, stopped).await?;
            match &earlier {
                Some(state) => save_drain(ctx, state).await,
                None => clear_drain(ctx).await,
            }
        };
        if let Err(failure) = restored.await {
            warn!(
                error = format!("{failure:#}"),
                "unable to restore scheduling"
            );
        }
    }
    waited
}

async fn wait_for_workers(ctx: &Ctx, timeout: u64, signals: &Shutdown) -> Result<()> {
    let deadline = Instant::now() + Duration::from_secs(timeout);
    loop {
        if signals.requested() {
            bail!("Drain interrupted; restoring the pre-drain processing state.");
        }
        let active = busy::active_roles(ctx).await?;
        if active.is_empty() {
            return Ok(());
        }
        if Instant::now() >= deadline {
            bail!(
                "Drain timeout reached; active workers were preserved. Blocking roles: {}.",
                active.join(", ")
            );
        }
        signals.sleep(POLL).await;
    }
}

/// Restores the state from before the drain. Returns what happened, for the operator.
pub async fn resume(ctx: &Ctx, token: Option<&str>) -> Result<&'static str> {
    const NOT_OWNED: &str = "This operation owns no active drain; processing state was preserved.";
    check_token(token)?;
    let lock = control_lock(ctx).await?;
    let result = async {
        let Some(state) = saved_drain(ctx).await? else {
            if token.is_some() {
                return Ok(NOT_OWNED);
            }
            bail!("There is no saved pre-drain state to restore.");
        };
        if token.is_some_and(|token| state["token"] != token) {
            return Ok(NOT_OWNED);
        }
        if !flags(ctx).await?.1 {
            clear_drain(ctx).await?;
            return Ok("Processing isn't paused; cleared the stale drain state.");
        }
        let (Some(running), Some(stopped)) =
            (state["running"].as_bool(), state["stopped"].as_bool())
        else {
            bail!("The saved pre-drain state is unreadable; processing stays paused.");
        };
        set_flags(ctx, running, stopped).await?;
        clear_drain(ctx).await?;
        Ok("Pre-drain processing state restored.")
    }
    .await;
    let _ = lock.close().await;
    result
}

fn heartbeat_limit() -> i64 {
    let delay = parsed_or("TMUX_MONITOR_DELAY", 10i64).unwrap_or(10);
    let limit = parsed_or("TMUX_HEARTBEAT_MAX_AGE", 180i64).unwrap_or(180);
    limit.max(delay + 15).max(1)
}

/// Fails if processing should be running and the supervisor isn't beating. Compose handles the
/// restart that `--auto-restart` used to do.
pub async fn health_check(ctx: &Ctx) -> Result<&'static str> {
    let (running, stopped) = flags(ctx).await?;
    if stopped || !running {
        return Ok("Processing is stopped intentionally.");
    }
    let mut redis = ctx.redis.clone();
    let beat: Option<i64> = redis.get(heartbeat_key(ctx)).await?;
    let Some(beat) = beat else {
        bail!("The supervisor has never recorded a heartbeat.");
    };
    let age = Utc::now().timestamp() - beat;
    if age > heartbeat_limit() {
        bail!("The supervisor's heartbeat is stale: {age} seconds old.");
    }
    Ok("The supervisor is alive.")
}
