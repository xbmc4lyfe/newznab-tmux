//! The `fingerprint` role: database-backed collection workers and the stale sweep.

use std::sync::Arc;
use std::time::Duration;

use anyhow::{Context, Result, anyhow, bail};
use nntmux_core::{parsed_or, var_or};
use nntmux_nntp::NntpConfig;
use nntmux_nntp::fingerprint::Provider;
use nntmux_runtime::{Ctx, RoleArgs, Shutdown, busy};
use sqlx::MySqlPool;
use tokio::task::JoinSet;
use tracing::{error, info, warn};

use crate::{backfill, collect, database};

const JOB_TIMEOUT: Duration = Duration::from_secs(backfill::TIMEOUT_SECONDS as u64);
const RENEW_EVERY: Duration = Duration::from_secs(30);
const WRITE_TIMEOUT: Duration = Duration::from_secs(10);
const IDLE: Duration = Duration::from_secs(2);
const STALE_SWEEP_EVERY: Duration = Duration::from_secs(15 * 60);
const STALE_SWEEP_LIMIT: i64 = 200;

#[derive(Clone, Debug)]
pub struct Settings {
    pub worker: String,
    pub concurrency: usize,
    pub providers: Vec<Provider>,
    pub alternate_percent: u32,
    pub timeout_seconds: u64,
}

impl Settings {
    pub fn from_env(nntp: &NntpConfig) -> Result<Self> {
        if nntp.primary.host.trim().is_empty() {
            bail!("NNTP_SERVER is required");
        }
        let mut providers = vec![provider("primary", &nntp.primary)];
        if let Some(alternate) = nntp
            .alternate
            .as_ref()
            .filter(|alternate| !alternate.host.trim().is_empty())
        {
            providers.push(provider("alternate", alternate));
        }
        let worker = var_or(
            "FINGERPRINT_WORKER_ID",
            &format!(
                "rust-{}-{}",
                var_or("HOSTNAME", "worker"),
                std::process::id()
            ),
        );
        if worker.len() > 120 {
            bail!("Worker id exceeds 120 bytes");
        }
        Ok(Self {
            worker,
            concurrency: parsed_or("FINGERPRINT_CONCURRENCY", 8_usize)?.clamp(1, 128),
            providers,
            alternate_percent: parsed_or("FINGERPRINT_ALTERNATE_PERCENT", 10_u32)?.min(100),
            timeout_seconds: parsed_or("NNTP_TIMEOUT", 20_u64)?.clamp(1, 60),
        })
    }
}

fn provider(account: &str, from: &nntmux_nntp::Provider) -> Provider {
    Provider {
        account: account.to_owned(),
        host: from.host.clone(),
        port: from.port,
        tls: from.tls,
        username: from.username.clone(),
        password: from.password.clone(),
    }
}

/// Collects one claimed job, renewing its lease beside the work. Cancelling collection drops all
/// its sockets before their shared slots are released.
async fn collect_claim(
    pool: &MySqlPool,
    claim: &database::Claim,
    settings: &Settings,
) -> Result<collect::Collected> {
    let work = tokio::time::timeout(
        JOB_TIMEOUT,
        collect::run(
            pool,
            claim,
            &settings.worker,
            &settings.providers,
            settings.alternate_percent,
            settings.timeout_seconds,
        ),
    );
    tokio::pin!(work);
    loop {
        tokio::select! {
            result = &mut work => return result.context("Fingerprint job timed out")?,
            () = tokio::time::sleep(RENEW_EVERY) => {
                match tokio::time::timeout(WRITE_TIMEOUT, database::renew(pool, claim)).await {
                    Ok(Ok(true)) => {}
                    _ => return Err(anyhow!("ClaimLeaseLost")),
                }
            }
        }
    }
}

/// Claims and collects one job. `false` when none is due. Job failures go on the job, so an error
/// here means the database couldn't be written, and leases recover the work.
pub async fn work_once(pool: &MySqlPool, settings: &Settings) -> Result<bool> {
    let Some(claim) = database::claim(pool, &settings.worker).await? else {
        return Ok(false);
    };
    let result = collect_claim(pool, &claim, settings).await;
    let mutation = async {
        match result {
            Ok(collected) => {
                let saved =
                    database::finish(pool, &claim, &collected.status, &collected.data).await?;
                if saved {
                    info!(release = claim.release_id, status = %collected.status, "fingerprint complete");
                } else {
                    info!(
                        release = claim.release_id,
                        "stale fingerprint result discarded"
                    );
                }
            }
            Err(failure) => {
                warn!(
                    release = claim.release_id,
                    error = format!("{failure:#}"),
                    "fingerprint attempt failed"
                );
                database::fail(pool, &claim, "FingerprintCollectionFailed").await?;
            }
        }
        Ok::<(), anyhow::Error>(())
    };
    let mutation = tokio::time::timeout(WRITE_TIMEOUT, mutation).await;
    // Attempted even when persistence failed. Expired slots recover if the database is down.
    let cleanup = tokio::time::timeout(WRITE_TIMEOUT, database::release_slots(pool, &claim)).await;
    if !matches!(mutation, Ok(Ok(()))) || !matches!(cleanup, Ok(Ok(()))) {
        bail!("Database result or cleanup failed; job leases recover automatically");
    }
    Ok(true)
}

pub async fn run(ctx: Ctx, args: RoleArgs, stop: Shutdown) -> Result<()> {
    let settings = Arc::new(Settings::from_env(&NntpConfig::from_env())?);
    let mut tasks = JoinSet::new();
    for _ in 0..settings.concurrency {
        let (ctx, stop, settings) = (ctx.clone(), stop.clone(), settings.clone());
        let once = args.once;
        tasks.spawn(async move {
            while !stop.requested() {
                let busy = match busy::enter(&ctx, "fingerprint").await {
                    Ok(Some(busy)) => busy,
                    Ok(None) => {
                        if once {
                            return;
                        }
                        stop.sleep(Duration::from_secs(5)).await;
                        continue;
                    }
                    Err(failure) => {
                        error!(error = format!("{failure:#}"), "can't mark this worker busy");
                        if once {
                            return;
                        }
                        stop.sleep(IDLE).await;
                        continue;
                    }
                };
                let worked = work_once(&ctx.db, &settings).await;
                drop(busy);
                match worked {
                    Ok(true) => {}
                    Ok(false) => {
                        if once {
                            return;
                        }
                        stop.sleep(IDLE).await;
                    }
                    Err(failure) => {
                        error!(error = format!("{failure:#}"), "fingerprint worker failed");
                        if once {
                            return;
                        }
                        stop.sleep(IDLE).await;
                    }
                }
            }
        });
    }
    if !args.once {
        let (db, stop) = (ctx.db.clone(), stop.clone());
        tasks.spawn(async move {
            // Records an upgrade marked stale, and runs whose worker died.
            while !stop.requested() {
                let options = backfill::Options {
                    limit: STALE_SWEEP_LIMIT,
                    after_id: 0,
                    delay_ms: 0,
                    dry_run: false,
                    stale: true,
                };
                match backfill::backfill(&db, &options).await {
                    Ok(ids) if !ids.is_empty() => {
                        info!(count = ids.len(), "stale fingerprints requeued")
                    }
                    Ok(_) => {}
                    Err(failure) => warn!(
                        error = format!("{failure:#}"),
                        "stale fingerprint sweep failed"
                    ),
                }
                stop.sleep(STALE_SWEEP_EVERY).await;
            }
        });
    }
    while tasks.join_next().await.is_some() {}
    Ok(())
}
