//! Finding documents that are missing or orphaned, from `nntmux:search-reconcile`.
//!
//! PHP treated a failed id probe as "none indexed", which reported every row in the batch as
//! missing. Here a failed probe stops the run, so a Manticore outage can't look like drift.

use std::collections::HashSet;

use chrono::{NaiveDate, NaiveDateTime, Utc};
use serde_json::Value;
use sqlx::MySqlPool;

use crate::read::{PredbHit, parse_id};
use crate::{SearchClient, SearchError};

#[derive(Clone, Debug)]
pub struct ReconcileOptions {
    /// Only releases added since this time. None scans every row.
    pub since: Option<NaiveDateTime>,
    pub chunk: u32,
    pub in_batch: u32,
    pub orphans: bool,
    pub reindex: bool,
}

#[derive(Clone, Debug, Default, PartialEq, Eq)]
pub struct ReconcileReport {
    pub scanned: usize,
    pub missing: Vec<i64>,
    pub orphans: Vec<i64>,
    pub reindexed: usize,
    pub orphans_removed: bool,
}

#[derive(Clone, Debug, Default, PartialEq, Eq)]
pub struct PredbReconcileReport {
    pub scanned: usize,
    pub missing: usize,
    pub sample: Vec<i64>,
    pub repaired: usize,
    pub last_completed_id: i64,
    pub max_id: i64,
    /// Set when the run stopped early. `last_completed_id` says how far it got.
    pub failure: Option<String>,
}

/// `24h`, `7d` or a date. None when it can't be read; the caller falls back to 24 hours.
pub fn parse_since(since: &str, now: NaiveDateTime) -> Option<NaiveDateTime> {
    let since = since.trim();
    let lowered = since.to_ascii_lowercase();
    if let Some(hours) = lowered
        .strip_suffix('h')
        .and_then(|n| n.parse::<i64>().ok())
    {
        return now.checked_sub_signed(chrono::Duration::try_hours(hours)?);
    }
    if let Some(days) = lowered
        .strip_suffix('d')
        .and_then(|n| n.parse::<i64>().ok())
    {
        return now.checked_sub_signed(chrono::Duration::try_days(days)?);
    }
    NaiveDateTime::parse_from_str(since, "%Y-%m-%d %H:%M:%S")
        .ok()
        .or_else(|| {
            NaiveDate::parse_from_str(since, "%Y-%m-%d")
                .ok()
                .and_then(|date| date.and_hms_opt(0, 0, 0))
        })
}

fn database(error: sqlx::Error) -> SearchError {
    SearchError::Database(error.to_string())
}

fn id_list(ids: &[i64]) -> String {
    ids.iter().map(i64::to_string).collect::<Vec<_>>().join(",")
}

impl SearchClient {
    /// Which of `ids` are indexed. Manticore's implicit LIMIT 20 and default `max_matches` would
    /// flag the rest as missing, so both are set to the batch size. The table is quoted, as in
    /// PHP, and comes from configuration.
    async fn probe_indexed(&self, table: &str, ids: &[i64]) -> Result<HashSet<i64>, SearchError> {
        let rows = self.sql(&probe_statement(table, ids)).await?;
        rows.iter()
            .map(|row| parse_id(row.get("id").unwrap_or(&Value::Null)))
            .collect()
    }

    /// Fails closed: rejects warnings, a total that disagrees with the rows, or an id nobody
    /// asked for, so a partial answer can't trigger a repair.
    async fn probe_indexed_strict(
        &self,
        table: &str,
        ids: &[i64],
    ) -> Result<HashSet<i64>, SearchError> {
        let envelope = self.sql_envelope(&probe_statement(table, ids)).await?;
        let rows = envelope.get("data").and_then(Value::as_array);
        let total = envelope.get("total").and_then(Value::as_u64);
        let warning = envelope.get("warning").and_then(Value::as_str);
        let (Some(rows), Some(total), Some("")) = (rows, total, warning) else {
            return Err(SearchError::Malformed(format!(
                "invalid index probe response: {envelope}"
            )));
        };
        if total != rows.len() as u64 {
            return Err(SearchError::Malformed(
                "the index probe total disagrees with its rows".to_owned(),
            ));
        }
        let asked: HashSet<i64> = ids.iter().copied().collect();
        let mut indexed = HashSet::with_capacity(rows.len());
        for row in rows {
            let id = parse_id(row.get("id").unwrap_or(&Value::Null))?;
            if !asked.contains(&id) {
                return Err(SearchError::Malformed(format!(
                    "the index probe returned id {id}, which wasn't asked for"
                )));
            }
            indexed.insert(id);
        }
        Ok(indexed)
    }

    pub async fn reconcile_releases(
        &self,
        db: &MySqlPool,
        options: &ReconcileOptions,
    ) -> Result<ReconcileReport, SearchError> {
        let chunk = options.chunk.clamp(50, 5000);
        let in_batch = usize::try_from(options.in_batch.clamp(50, 1000)).unwrap_or(1000);
        let table = self.config.indexes.table("releases").to_owned();
        let mut report = ReconcileReport::default();
        let mut after = 0_i64;
        loop {
            let ids: Vec<i64> =
                match options.since {
                    Some(since) => {
                        sqlx::query_scalar(
                            "SELECT CAST(id AS SIGNED) FROM releases WHERE adddate >= ? AND id > ? \
                     ORDER BY id LIMIT ?",
                        )
                        .bind(since)
                        .bind(after)
                        .bind(chunk)
                        .fetch_all(db)
                        .await
                    }
                    None => sqlx::query_scalar(
                        "SELECT CAST(id AS SIGNED) FROM releases WHERE id > ? ORDER BY id LIMIT ?",
                    )
                    .bind(after)
                    .bind(chunk)
                    .fetch_all(db)
                    .await,
                }
                .map_err(database)?;
            let Some(last) = ids.last() else {
                break;
            };
            after = *last;
            report.scanned += ids.len();
            for batch in ids.chunks(in_batch) {
                let indexed = self.probe_indexed(&table, batch).await?;
                report
                    .missing
                    .extend(batch.iter().copied().filter(|id| !indexed.contains(id)));
            }
        }

        if options.orphans {
            report.orphans = self.find_orphans(db, chunk).await?;
        }
        if options.reindex {
            for id in &report.missing {
                report.reindexed += usize::from(self.update_release(db, *id).await);
            }
            if !report.orphans.is_empty() {
                report.orphans_removed = self.delete_releases(db, &report.orphans).await;
            }
        }
        Ok(report)
    }

    async fn find_orphans(&self, db: &MySqlPool, page: u32) -> Result<Vec<i64>, SearchError> {
        let mut orphans = Vec::new();
        let mut offset = 0_u64;
        loop {
            let indexed = self.indexed_release_ids(u64::from(page), offset).await?;
            if indexed.is_empty() {
                break;
            }
            let statement = format!(
                "SELECT CAST(id AS SIGNED) FROM releases WHERE id IN ({})",
                id_list(&indexed)
            );
            let existing: HashSet<i64> = sqlx::query_scalar(sqlx::AssertSqlSafe(statement))
                .fetch_all(db)
                .await
                .map_err(database)?
                .into_iter()
                .collect();
            orphans.extend(indexed.iter().copied().filter(|id| !existing.contains(id)));
            offset += indexed.len() as u64;
            if (indexed.len() as u64) < u64::from(page) {
                break;
            }
        }
        Ok(orphans)
    }

    /// Scans up to the id that was highest at the start. Repairs use INSERT, which rejects ids
    /// a producer indexed since the probe, keeping its fresher document and making a rerun safe.
    pub async fn reconcile_predb(
        &self,
        db: &MySqlPool,
        chunk: u32,
        in_batch: u32,
        reindex: bool,
    ) -> PredbReconcileReport {
        let mut report = PredbReconcileReport::default();
        if let Err(error) = self
            .scan_predb(db, chunk, in_batch, reindex, &mut report)
            .await
        {
            report.failure = Some(error.to_string());
        }
        report
    }

    async fn scan_predb(
        &self,
        db: &MySqlPool,
        chunk: u32,
        in_batch: u32,
        reindex: bool,
        report: &mut PredbReconcileReport,
    ) -> Result<(), SearchError> {
        let chunk = chunk.clamp(50, 5000);
        let in_batch = usize::try_from(in_batch.clamp(50, 1000)).unwrap_or(1000);
        let table = self.config.indexes.table("predb").to_owned();
        report.max_id =
            sqlx::query_scalar::<_, Option<i64>>("SELECT CAST(MAX(id) AS SIGNED) FROM predb")
                .fetch_one(db)
                .await
                .map_err(database)?
                .unwrap_or(0);
        let mut after = 0_i64;
        loop {
            let rows: Vec<(i64, String, String, String)> = sqlx::query_as(
                "SELECT CAST(id AS SIGNED), title, filename, source FROM predb \
                 WHERE id > ? AND id <= ? ORDER BY id LIMIT ?",
            )
            .bind(after)
            .bind(report.max_id)
            .bind(chunk)
            .fetch_all(db)
            .await
            .map_err(database)?;
            let Some(last) = rows.last().map(|row| row.0) else {
                return Ok(());
            };
            for batch in rows.chunks(in_batch) {
                let ids: Vec<i64> = batch.iter().map(|row| row.0).collect();
                let indexed = self.probe_indexed_strict(&table, &ids).await?;
                let missing: Vec<PredbHit> = batch
                    .iter()
                    .filter(|row| !indexed.contains(&row.0))
                    .map(|(id, title, filename, source)| PredbHit {
                        id: *id,
                        title: title.clone(),
                        filename: filename.clone(),
                        source: source.clone(),
                    })
                    .collect();
                report.scanned += ids.len();
                report.missing += missing.len();
                for hit in missing.iter().take(20 - report.sample.len().min(20)) {
                    report.sample.push(hit.id);
                }
                if reindex && !missing.is_empty() {
                    let written = self.bulk_insert_predb(&missing, false).await;
                    if written.success != missing.len() || written.errors != 0 {
                        return Err(SearchError::Query("the PreDB bulk write failed".to_owned()));
                    }
                    let written_ids: Vec<i64> = missing.iter().map(|hit| hit.id).collect();
                    let now_indexed = self.probe_indexed_strict(&table, &written_ids).await?;
                    if written_ids.iter().any(|id| !now_indexed.contains(id)) {
                        return Err(SearchError::Query(
                            "the PreDB write didn't verify".to_owned(),
                        ));
                    }
                    report.repaired += missing.len();
                }
            }
            after = last;
            report.last_completed_id = last;
        }
    }
}

fn probe_statement(table: &str, ids: &[i64]) -> String {
    let table = table.replace('`', "``");
    let limit = ids.len().max(1);
    format!(
        "SELECT id FROM `{table}` WHERE id IN ({}) LIMIT {limit} OPTION max_matches={limit}",
        id_list(ids)
    )
}

/// The default `--since` window.
pub fn default_since() -> NaiveDateTime {
    Utc::now().naive_utc() - chrono::Duration::hours(24)
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn since_takes_windows_and_dates() {
        let now = NaiveDate::from_ymd_opt(2026, 11, 2)
            .unwrap()
            .and_hms_opt(12, 0, 0)
            .unwrap();
        assert_eq!(
            parse_since("24h", now).unwrap().to_string(),
            "2026-11-01 12:00:00"
        );
        assert_eq!(
            parse_since(" 7D ", now).unwrap().to_string(),
            "2026-10-26 12:00:00"
        );
        assert_eq!(
            parse_since("2026-01-02", now).unwrap().to_string(),
            "2026-01-02 00:00:00"
        );
        assert_eq!(
            parse_since("2026-01-02 03:04:05", now).unwrap().to_string(),
            "2026-01-02 03:04:05"
        );
        assert_eq!(parse_since("soon", now), None);
        assert_eq!(parse_since("9999999999999999d", now), None);
    }

    #[test]
    fn probes_set_their_limit_to_the_batch() {
        assert_eq!(
            probe_statement("rel`x", &[1, 2]),
            "SELECT id FROM `rel``x` WHERE id IN (1,2) LIMIT 2 OPTION max_matches=2"
        );
    }
}
