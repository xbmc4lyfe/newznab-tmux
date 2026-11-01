//! The ingestion settings release processing reads, and its chunk limits.

use anyhow::Result;
use nntmux_core::parsed_or;
use sqlx::MySqlPool;

/// `ingestion_configurations`, row 1. A missing row means the app's defaults.
#[derive(Clone, Debug, PartialEq, Eq, sqlx::FromRow)]
pub struct Settings {
    #[sqlx(rename = "release_threads", try_from = "u16")]
    pub release_threads: i64,
    #[sqlx(rename = "collection_delay_hours", try_from = "u32")]
    pub collection_delay_hours: i64,
    #[sqlx(rename = "max_releases_created", try_from = "u64")]
    pub release_creation_limit: i64,
    #[sqlx(rename = "completion_percent", try_from = "u32")]
    pub completion: i64,
    #[sqlx(rename = "max_size_to_form_release", try_from = "u64")]
    pub max_size: i64,
    #[sqlx(rename = "min_size_to_form_release", try_from = "u64")]
    pub min_size: i64,
    #[sqlx(rename = "min_files_to_form_release", try_from = "u32")]
    pub min_files: i64,
    #[sqlx(rename = "release_retention_days", try_from = "u32")]
    pub retention_days: i64,
    #[sqlx(rename = "delete_passworded_releases")]
    pub delete_passworded: bool,
    #[sqlx(rename = "misc_other_retention_hours", try_from = "u32")]
    pub misc_other_retention_hours: i64,
    #[sqlx(rename = "misc_hashed_retention_hours", try_from = "u32")]
    pub misc_hashed_retention_hours: i64,
    #[sqlx(rename = "part_retention_hours", try_from = "u32")]
    pub part_retention_hours: i64,
}

impl Default for Settings {
    fn default() -> Self {
        Self {
            release_threads: 1,
            collection_delay_hours: 2,
            release_creation_limit: 1000,
            completion: 95,
            max_size: 0,
            min_size: 0,
            min_files: 1,
            retention_days: 0,
            delete_passworded: false,
            misc_other_retention_hours: 0,
            misc_hashed_retention_hours: 0,
            part_retention_hours: 72,
        }
    }
}

impl Settings {
    pub async fn load(pool: &MySqlPool) -> Result<Self> {
        let row = sqlx::query_as("SELECT * FROM ingestion_configurations WHERE id = 1")
            .fetch_optional(pool)
            .await?;
        let mut settings: Self = row.unwrap_or_default();
        // Clamped to its upper bound, as the PHP settings object did.
        settings.completion = settings.completion.min(100);
        Ok(settings)
    }
}

/// `config/nntmux.php`'s `cbp` limits on one reconciliation pass.
#[derive(Clone, Copy, Debug)]
pub struct Limits {
    pub sql_chunk_size: usize,
    pub reconcile_batch_size: i64,
    pub reconcile_pages_per_pass: i64,
    pub reconcile_pass_seconds: u64,
}

impl Limits {
    pub fn from_env() -> Result<Self> {
        Ok(Self {
            sql_chunk_size: parsed_or::<i64>("CBP_SQL_CHUNK_SIZE", 500)?.clamp(50, 1000) as usize,
            reconcile_batch_size: parsed_or::<i64>("CBP_RECONCILE_BATCH_SIZE", 500)?
                .clamp(50, 2000),
            reconcile_pages_per_pass: parsed_or::<i64>("CBP_RECONCILE_PAGES_PER_PASS", 1000)?
                .max(1),
            reconcile_pass_seconds: parsed_or::<i64>("CBP_RECONCILE_PASS_SECONDS", 300)?.max(1)
                as u64,
        })
    }
}

/// Whether an operator or a deploy asked processing to stop.
pub async fn stop_requested(pool: &MySqlPool) -> Result<bool> {
    let stop: Option<bool> =
        sqlx::query_scalar("SELECT stop_requested FROM processing_runtime_states WHERE id = 1")
            .fetch_optional(pool)
            .await?;
    Ok(stop.unwrap_or(false))
}
