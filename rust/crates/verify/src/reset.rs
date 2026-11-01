//! Clearing all verification state around a database reset.

use std::future::Future;
use std::os::unix::fs::DirBuilderExt;
use std::path::Path;

use anyhow::{Context, Result, anyhow, bail};
use rand::Rng;

use crate::context::Verify;
use crate::in_tx;
use crate::locks::{self, LockSession};

const BUSY: &str = "Verification worker, admission or reset is active; retry reset later.";
/// What the storage directory keeps across a reset.
const KEPT: [&str; 4] = ["worker.lock", "reset.lock", "locks", ".gitignore"];

/// Runs a database reset with admissions and workers held out on every host.
///
/// A durable pause fences the whole reset, including the filesystem cleanup a lost session could
/// not stop. If this process dies, the pause stays until an operator runs
/// `verify barrier --release --all`.
pub async fn run<F, Fut, T>(verify: &Verify, reset: F) -> Result<T>
where
    F: FnOnce() -> Fut,
    Fut: Future<Output = Result<T>>,
{
    let mut session = LockSession::open(&verify.db).await?;
    let admission = session
        .exclusive_group("reset")
        .await?
        .ok_or(anyhow!(BUSY))?;
    let workers = session
        .exclusive_group("worker")
        .await?
        .ok_or(anyhow!(BUSY))?;
    // Checked while holding both gates, so a pause can't appear unseen before the work.
    if locks::paused(&verify.db, None).await? {
        session.release(workers).await;
        session.release(admission).await;
        bail!("A deployment pause holds verification workers; retry reset after it is released.");
    }
    let token = format!("reset-{:016x}", rand::rng().random::<u64>());
    locks::pause(&verify.db, &token, "reset").await?;
    let outcome = match clear(verify).await {
        Ok(()) => reset().await,
        Err(failure) => Err(failure),
    };
    let released = locks::unpause(&verify.db, Some(&token), None).await;
    session.release(workers).await;
    session.release(admission).await;
    let value = outcome?;
    released?;
    Ok(value)
}

/// Deletes verification rows and this host's scratch files. Other hosts keep their scratch records
/// and budgets, marked terminal, because only they can remove their directories.
async fn clear(verify: &Verify) -> Result<()> {
    let host = verify.config.host.as_str();
    in_tx!(verify.db, 5, |tx| {
        sqlx::query("SELECT id FROM release_verification_state WHERE id = 1 FOR UPDATE")
            .execute(&mut *tx)
            .await?;
        sqlx::query("UPDATE release_verification_scratch SET terminal = 1 WHERE host <> ?")
            .bind(host)
            .execute(&mut *tx)
            .await?;
        // Partials keep no inventory once candidates are gone, so NZB-reading workers process them.
        sqlx::query(
            "UPDATE releases SET late_article_candidate_id = NULL WHERE late_article_candidate_id IS NOT NULL",
        )
        .execute(&mut *tx)
        .await?;
        sqlx::query("DELETE FROM release_verification_scratch WHERE host = ?")
            .bind(host)
            .execute(&mut *tx)
            .await?;
        for table in [
            "release_verification_candidates",
            "release_verification_audit_retries",
            "release_verification_manifests",
            "release_verification_oversized_collections",
        ] {
            sqlx::query(sqlx::AssertSqlSafe(format!("DELETE FROM {table}")))
                .execute(&mut *tx)
                .await?;
        }
        sqlx::query("DELETE FROM release_verification_scratch_budget WHERE host = ?")
            .bind(host)
            .execute(&mut *tx)
            .await?;
        if host.is_empty() {
            sqlx::query("INSERT INTO release_verification_scratch_budget (id) VALUES (1)")
                .execute(&mut *tx)
                .await?;
        }
        sqlx::query("DELETE FROM release_verification_state")
            .execute(&mut *tx)
            .await?;
        sqlx::query(
            "INSERT INTO release_verification_state (id, pending_bytes, audit_cursor, audit_retry_turn) VALUES (1, 0, 0, 0)",
        )
        .execute(&mut *tx)
        .await?;
        Ok::<_, anyhow::Error>(())
    })?;
    clear_storage(&verify.config.storage_path)
}

fn clear_storage(root: &Path) -> Result<()> {
    std::fs::DirBuilder::new()
        .recursive(true)
        .mode(0o700)
        .create(root)
        .with_context(|| format!("creating {}", root.display()))?;
    for entry in std::fs::read_dir(root)? {
        let entry = entry?;
        if KEPT.iter().any(|kept| entry.file_name() == *kept) {
            continue;
        }
        let path = entry.path();
        let kind = entry.file_type()?;
        let removed = if kind.is_dir() {
            // A folder that something refilled meanwhile gets one more pass.
            std::fs::remove_dir_all(&path).or_else(|_| std::fs::remove_dir_all(&path))
        } else {
            std::fs::remove_file(&path)
        };
        match removed {
            Ok(()) => {}
            Err(failure) if failure.kind() == std::io::ErrorKind::NotFound => {}
            Err(failure) => {
                return Err(failure).context("Cannot remove verification reset artifacts.");
            }
        }
    }
    Ok(())
}
