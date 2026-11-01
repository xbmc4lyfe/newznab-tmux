//! `missed_parts`, the queue of articles part repair still owes.

use std::collections::BTreeSet;

use anyhow::Result;
use sqlx::{AssertSqlSafe, MySqlPool};
use tracing::warn;

/// The part repair limits.
#[derive(Clone, Copy, Debug)]
pub struct MissedParts {
    pub limit: u64,
    pub max_tries: i64,
    pub chunk_size: usize,
}

impl MissedParts {
    pub fn new(limit: u64, max_tries: i64, chunk_size: usize) -> Self {
        Self {
            limit,
            max_tries,
            chunk_size: chunk_size.clamp(50, 1000),
        }
    }

    /// Queues articles, counting one more attempt on any already queued.
    pub async fn add(&self, pool: &MySqlPool, numbers: &[u64], group: u32) -> Result<()> {
        let unique: Vec<u64> = numbers
            .iter()
            .copied()
            .collect::<BTreeSet<_>>()
            .into_iter()
            .collect();
        for chunk in unique.chunks(self.chunk_size) {
            let values = vec!["(?, ?, 1)"; chunk.len()].join(",");
            let sql = format!(
                "INSERT INTO missed_parts (numberid, groups_id, attempts) VALUES {values} \
                 ON DUPLICATE KEY UPDATE attempts = attempts + 1"
            );
            let mut query = sqlx::query(AssertSqlSafe(sql));
            for number in chunk {
                query = query.bind(number).bind(group);
            }
            query.execute(pool).await?;
        }
        Ok(())
    }

    /// Queues a range that was never tried, without using up retries of any already queued.
    pub async fn defer_range(
        &self,
        pool: &MySqlPool,
        first: u64,
        last: u64,
        group: u32,
    ) -> Result<()> {
        let mut start = first;
        while start <= last {
            let end = last.min(start.saturating_add(self.chunk_size as u64 - 1));
            let count = usize::try_from(end - start + 1).unwrap_or(self.chunk_size);
            let values = vec!["(?, ?, 0)"; count].join(",");
            let sql = format!(
                "INSERT INTO missed_parts (numberid, groups_id, attempts) VALUES {values} \
                 ON DUPLICATE KEY UPDATE numberid = VALUES(numberid)"
            );
            let mut query = sqlx::query(AssertSqlSafe(sql));
            for number in start..=end {
                query = query.bind(number).bind(group);
            }
            query.execute(pool).await?;
            let Some(next) = end.checked_add(1) else {
                break;
            };
            start = next;
        }
        Ok(())
    }

    /// A failed delete only means repaired articles get fetched again.
    pub async fn remove(&self, pool: &MySqlPool, numbers: &[u64], group: u32) {
        for chunk in numbers.chunks(self.chunk_size) {
            let marks = vec!["?"; chunk.len()].join(",");
            let sql =
                format!("DELETE FROM missed_parts WHERE groups_id = ? AND numberid IN ({marks})");
            let mut query = sqlx::query(AssertSqlSafe(sql)).bind(group);
            for number in chunk {
                query = query.bind(number);
            }
            if let Err(failure) = query.execute(pool).await {
                warn!(group, error = %failure, "couldn't remove repaired parts");
            }
        }
    }

    /// The numbers to repair, lowest first.
    pub async fn pending(&self, pool: &MySqlPool, group: u32) -> Result<Vec<u64>> {
        Ok(sqlx::query_scalar(
            "SELECT numberid FROM missed_parts WHERE groups_id = ? AND attempts < ? \
             ORDER BY numberid LIMIT ?",
        )
        .bind(group)
        .bind(self.max_tries)
        .bind(self.limit)
        .fetch_all(pool)
        .await?)
    }

    /// One more attempt on everything up to `max_number`, after a repair pass.
    pub async fn increment_up_to(
        &self,
        pool: &MySqlPool,
        group: u32,
        max_number: u64,
    ) -> Result<()> {
        sqlx::query(
            "UPDATE missed_parts SET attempts = attempts + 1 WHERE groups_id = ? AND numberid <= ?",
        )
        .bind(group)
        .bind(max_number)
        .execute(pool)
        .await?;
        Ok(())
    }

    /// Gives up on articles that used all their tries.
    pub async fn remove_exhausted(&self, pool: &MySqlPool, group: u32) -> Result<()> {
        sqlx::query("DELETE FROM missed_parts WHERE groups_id = ? AND attempts >= ?")
            .bind(group)
            .bind(self.max_tries)
            .execute(pool)
            .await?;
        Ok(())
    }
}
