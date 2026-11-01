//! Retrying failed index updates, from `nntmux:search-repair`. The `search-sync` role runs this
//! continuously, which also replaces PHP's `ReindexReleaseJob`: a failed write's row comes due
//! here two seconds later.

use chrono::Utc;
use sqlx::MySqlPool;

use crate::index::DEFERRED_OPERATION;
use crate::{SearchClient, SearchError};

#[derive(Clone, Copy, Debug, Default, PartialEq, Eq)]
pub struct RepairReport {
    /// Rows that were due.
    pub due: usize,
    /// Of those, the ones whose document now matches its row.
    pub repaired: usize,
}

#[derive(sqlx::FromRow)]
struct DueFailure {
    release_id: i64,
    operation: String,
    attempts: i64,
    last_error: Option<String>,
}

fn database(error: sqlx::Error) -> SearchError {
    SearchError::Database(error.to_string())
}

fn backoff_seconds(attempts: i64) -> i64 {
    2_i64
        .pow(u32::try_from(attempts.clamp(0, 10)).unwrap_or(10))
        .min(3600)
}

impl SearchClient {
    /// The ids of failures that are due, oldest first, up to `limit` (clamped to 1 through 5000).
    pub async fn due_failures(&self, db: &MySqlPool, limit: u32) -> Result<Vec<i64>, SearchError> {
        Ok(load_due(db, limit)
            .await?
            .iter()
            .map(|row| row.release_id)
            .collect())
    }

    /// Retries what's due.
    pub async fn repair(&self, db: &MySqlPool, limit: u32) -> Result<RepairReport, SearchError> {
        let rows = load_due(db, limit).await?;
        let mut report = RepairReport {
            due: rows.len(),
            repaired: 0,
        };
        for row in rows {
            let repaired = match row.operation.as_str() {
                "delete" => self.delete_releases(db, &[row.release_id]).await,
                DEFERRED_OPERATION => self.repair_expired_deferral(db, &row).await?,
                _ => self.update_release(db, row.release_id).await,
            };
            report.repaired += usize::from(repaired);
        }
        Ok(report)
    }

    /// Refreshes a release whose lease ran out: its pass died, ran long, or handed it over after
    /// a failed refresh. If the refresh worked, removes the marker unless the pass renewed it or
    /// someone counted a failure meanwhile. Otherwise retries it with backoff.
    async fn repair_expired_deferral(
        &self,
        db: &MySqlPool,
        row: &DueFailure,
    ) -> Result<bool, SearchError> {
        let token = row.last_error.as_deref().unwrap_or_default();
        let current = Utc::now().naive_utc();
        if self.update_releases(db, &[row.release_id]).await.is_empty() {
            sqlx::query(
                "DELETE FROM search_index_failures WHERE release_id = ? AND operation = ? \
                 AND last_error = ? AND next_attempt_at <= ? AND attempts = ?",
            )
            .bind(row.release_id)
            .bind(DEFERRED_OPERATION)
            .bind(token)
            .bind(current)
            .bind(row.attempts)
            .execute(db)
            .await
            .map_err(database)?;
            return Ok(true);
        }
        let next = current + chrono::Duration::seconds(backoff_seconds(row.attempts + 1));
        sqlx::query(
            "UPDATE search_index_failures SET next_attempt_at = ?, updated_at = ? \
             WHERE release_id = ? AND operation = ? AND last_error = ? AND next_attempt_at <= ?",
        )
        .bind(next)
        .bind(current)
        .bind(row.release_id)
        .bind(DEFERRED_OPERATION)
        .bind(token)
        .bind(current)
        .execute(db)
        .await
        .map_err(database)?;
        Ok(false)
    }
}

async fn load_due(db: &MySqlPool, limit: u32) -> Result<Vec<DueFailure>, SearchError> {
    sqlx::query_as(
        "SELECT CAST(release_id AS SIGNED) AS release_id, operation, \
         CAST(attempts AS SIGNED) AS attempts, last_error FROM search_index_failures \
         WHERE resolved_at IS NULL AND (next_attempt_at IS NULL OR next_attempt_at <= ?) \
         ORDER BY id LIMIT ?",
    )
    .bind(Utc::now().naive_utc())
    .bind(limit.clamp(1, 5000))
    .fetch_all(db)
    .await
    .map_err(database)
}
