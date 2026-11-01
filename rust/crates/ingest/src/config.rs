//! Ingestion settings: the `ingestion_configurations` row and the `CBP_*` limits.

use anyhow::{Context, Result};
use chrono::NaiveDate;
use nntmux_core::parsed_or;
use sqlx::{MySqlPool, Row};

#[derive(Clone, Debug)]
pub struct IngestConfig {
    /// Articles fetched per XOVER, the `max_messages` setting.
    pub message_buffer: u64,
    pub part_repair: bool,
    pub safe_part_repair: bool,
    pub new_group_scan_by_days: bool,
    pub new_group_messages_to_scan: u64,
    pub new_group_days_to_scan: i64,
    pub part_repair_limit: u64,
    pub part_repair_max_tries: i64,
    pub binary_threads: usize,
    pub backfill_threads: usize,
    pub release_threads: usize,
    pub max_headers_per_iteration: u64,
    pub safe_backfill_date: NaiveDate,
    pub disable_backfill_group: bool,
    pub backfill_days_mode: u32,
    pub backfill_order: u32,
    pub backfill_quantity: u64,
    /// Headers parsed and stored at a time. Each chunk is one transaction.
    pub header_chunk_size: usize,
    /// The most rows in one generated statement.
    pub sql_chunk_size: usize,
    /// Skip a range's articles randomised per article once this many share a subject shape.
    pub obfuscation_min_articles: usize,
    pub verification_required: bool,
    pub verification_pending_bytes: i64,
}

impl IngestConfig {
    pub async fn load(pool: &MySqlPool) -> Result<Self> {
        let row = sqlx::query(
            "SELECT max_messages, part_repair, safe_part_repair, new_group_scan_method,
                    new_group_messages_to_scan, new_group_days_to_scan, max_part_repair,
                    part_repair_max_tries, binary_threads, backfill_threads, release_threads,
                    max_headers_per_iteration, safe_backfill_date, disable_backfill_group,
                    backfill_days_mode, backfill_order, backfill_quantity
             FROM ingestion_configurations WHERE id = 1",
        )
        .fetch_one(pool)
        .await
        .context("reading ingestion_configurations")?;
        let header_chunk_size: i64 = parsed_or("CBP_HEADER_CHUNK_SIZE", 500)?;
        let sql_chunk_size: i64 = parsed_or("CBP_SQL_CHUNK_SIZE", 500)?;
        let obfuscation: i64 = parsed_or("CBP_PER_ARTICLE_OBFUSCATION_MIN_ARTICLES", 16)?;
        Ok(Self {
            message_buffer: row.try_get("max_messages")?,
            part_repair: row.try_get("part_repair")?,
            safe_part_repair: row.try_get("safe_part_repair")?,
            new_group_scan_by_days: row.try_get::<u32, _>("new_group_scan_method")? == 1,
            new_group_messages_to_scan: row.try_get("new_group_messages_to_scan")?,
            new_group_days_to_scan: i64::from(row.try_get::<u32, _>("new_group_days_to_scan")?),
            part_repair_limit: row.try_get("max_part_repair")?,
            part_repair_max_tries: i64::from(row.try_get::<u32, _>("part_repair_max_tries")?),
            binary_threads: usize::from(row.try_get::<u16, _>("binary_threads")?),
            backfill_threads: usize::from(row.try_get::<u16, _>("backfill_threads")?),
            release_threads: usize::from(row.try_get::<u16, _>("release_threads")?),
            max_headers_per_iteration: row.try_get("max_headers_per_iteration")?,
            safe_backfill_date: row.try_get("safe_backfill_date")?,
            disable_backfill_group: row.try_get("disable_backfill_group")?,
            backfill_days_mode: row.try_get("backfill_days_mode")?,
            backfill_order: row.try_get("backfill_order")?,
            backfill_quantity: row.try_get("backfill_quantity")?,
            header_chunk_size: clamp_chunk(header_chunk_size, 2000),
            sql_chunk_size: clamp_chunk(sql_chunk_size, 1000),
            obfuscation_min_articles: obfuscation_min_articles(obfuscation),
            verification_required: php_bool_env("RELEASE_VERIFICATION_REQUIRED", true),
            verification_pending_bytes: parsed_or(
                "RELEASE_VERIFICATION_PENDING_BYTES",
                10_737_418_240,
            )?,
        })
    }
}

fn clamp_chunk(value: i64, most: i64) -> usize {
    usize::try_from(value.clamp(50, most)).unwrap_or(50)
}

/// Zero or less disables the check. Any other value is at least 8, so a handful of unrelated
/// single articles can't form a group.
fn obfuscation_min_articles(configured: i64) -> usize {
    if configured <= 0 {
        0
    } else {
        usize::try_from(configured.max(8)).unwrap_or(8)
    }
}

fn php_bool_env(key: &str, default: bool) -> bool {
    match nntmux_core::optional(key) {
        Some(value) => !matches!(
            value.to_ascii_lowercase().as_str(),
            "0" | "false" | "off" | "no" | "(false)"
        ),
        None => default,
    }
}

/// Whether the verification backlog can take more headers. Ingest pauses when it can't.
pub async fn capacity_available(pool: &MySqlPool, config: &IngestConfig) -> Result<bool> {
    if !config.verification_required {
        return Ok(true);
    }
    let pending: Option<u64> =
        sqlx::query_scalar("SELECT pending_bytes FROM release_verification_state WHERE id = 1")
            .fetch_optional(pool)
            .await?;
    let capacity = config.verification_pending_bytes;
    let reserve = (capacity / 10).min(33_554_432);
    let pending = i64::try_from(pending.unwrap_or(0)).unwrap_or(i64::MAX);
    Ok(pending < capacity - reserve)
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn holds_limits_to_their_ranges() {
        assert_eq!(clamp_chunk(1, 1000), 50);
        assert_eq!(clamp_chunk(5000, 1000), 1000);
        assert_eq!(obfuscation_min_articles(0), 0);
        assert_eq!(obfuscation_min_articles(-1), 0);
        assert_eq!(obfuscation_min_articles(3), 8);
        assert_eq!(obfuscation_min_articles(16), 16);
    }
}
