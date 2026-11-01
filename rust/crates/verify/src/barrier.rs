//! The deployment's cross-host writer barrier: it stops verification workers on every host that
//! shares the database from claiming, waits for them to finish, and keeps them out until it's
//! stopped. The deployment runs it as a one-off container and stops it only after a healthy rollout,
//! so a failed migration keeps remote workers out until it's repaired.

use std::time::{Duration, Instant};

use anyhow::{Result, bail};
use nntmux_runtime::Shutdown;
use rand::RngCore;

use crate::context::Verify;
use crate::locks::{self, WorkerLocks};

const DEPLOYMENT: &str = "deployment";

/// Clears deployment pauses left by a barrier that didn't release; with `all`, those left by an
/// interrupted reset too.
pub async fn release(verify: &Verify, all: bool) -> Result<()> {
    let reason = (!all).then_some(DEPLOYMENT);
    locks::unpause(&verify.db, None, reason).await?;
    println!("barrier released");
    if locks::paused(&verify.db, reason).await? {
        bail!("a pause came back while releasing the barrier");
    }
    Ok(())
}

/// Holds the barrier until `stop`. The durable pause goes first: workers on every host stop claiming,
/// and it outlives this session, so losing the gate later can't readmit them.
pub async fn hold(verify: &Verify, timeout: u64, stop: Shutdown) -> Result<()> {
    let mut bytes = [0u8; 16];
    rand::rng().fill_bytes(&mut bytes);
    let token = hex::encode(bytes);
    locks::pause(&verify.db, &token, DEPLOYMENT).await?;
    let mut worker_locks = WorkerLocks::new(&verify.db, &verify.config).await?;
    if !worker_locks.drain(timeout as f64).await? {
        locks::unpause(&verify.db, Some(&token), None).await?;
        bail!("Verification workers are still running on a host; the barrier was not taken.");
    }
    // Another barrier's release clears every deployment pause; this one's is reasserted before
    // reporting readiness and on every check, so it always backs this barrier's gate.
    locks::pause(&verify.db, &token, DEPLOYMENT).await?;
    println!("barrier held");
    let mut checked = Instant::now();
    while !stop.requested() {
        if checked.elapsed() >= Duration::from_secs(5) {
            let held = worker_locks.protects(0).await
                && locks::pause(&verify.db, &token, DEPLOYMENT).await.is_ok();
            if !held {
                println!("barrier lost");
                bail!(
                    "The verification barrier lost its database session; the durable pause remains until released."
                );
            }
            checked = Instant::now();
        }
        stop.sleep(Duration::from_millis(200)).await;
    }
    // A healthy rollout also clears pauses left by earlier failed deployment attempts.
    locks::unpause(&verify.db, None, Some(DEPLOYMENT)).await?;
    println!("barrier released");
    worker_locks.close().await;
    Ok(())
}
