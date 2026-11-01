//! `nntmux-ctl releases retry-archive-inspection`: one more post-processing pass for releases whose
//! archives could not be read.

use anyhow::Result;
use nntmux_core::pcre::Pcre;
use nntmux_verify::candidates;
use sqlx::{AssertSqlSafe, MySqlPool};

const CHUNK_SIZE: u64 = 1000;
const DEFAULT_MIN_SIZE: u64 = 1_048_576;
const DEFAULT_MAX_SIZE: u64 = 107_374_182_400;

#[derive(Clone, Copy, Debug, Default, PartialEq, Eq)]
pub struct ArchiveCounts {
    pub seven_zip: u64,
    pub rar: u64,
    pub zip: u64,
    pub unknown: u64,
}

impl ArchiveCounts {
    pub fn total(&self) -> u64 {
        self.seven_zip + self.rar + self.zip + self.unknown
    }
}

/// Releases the worker's candidate query would pick up again once queued: an enabled category and a
/// size within the post-processing bounds.
async fn eligible(pool: &MySqlPool) -> Result<String> {
    let bounds: Option<(u64, u64)> = sqlx::query_as(
        "SELECT min_size_to_post_process, max_size_to_post_process FROM post_processing_configurations \
         ORDER BY id LIMIT 1",
    )
    .fetch_optional(pool)
    .await?;
    let (min, max) = bounds.unwrap_or((DEFAULT_MIN_SIZE, DEFAULT_MAX_SIZE));
    let mut sql =
        "passwordstatus = -1 AND haspreview = 0 AND nzbstatus = 1 AND archive_retry_at IS NULL \
                   AND categories_id IN (SELECT id FROM categories WHERE disablepreview = 0)"
            .to_owned();
    if min > 0 {
        sql.push_str(&format!(" AND size > {min}"));
    }
    if max > 0 {
        sql.push_str(&format!(" AND size < {max}"));
    }
    Ok(sql)
}

struct ArchiveTypes {
    seven_zip: Pcre,
    rar: Pcre,
    zip: Pcre,
}

impl ArchiveTypes {
    fn new() -> Result<Self> {
        Ok(Self {
            seven_zip: Pcre::new(r"/\.7z(\.\d{3})?\b/i")?,
            rar: Pcre::new(r"/\.(rar|r\d{2,3}|part\d+)\b/i")?,
            zip: Pcre::new(r"/\.zipx?\b/i")?,
        })
    }

    fn count(&self, counts: &mut ArchiveCounts, name: &str) {
        let name = name.as_bytes();
        if self.seven_zip.is_match(name) {
            counts.seven_zip += 1;
        } else if self.rar.is_match(name) {
            counts.rar += 1;
        } else if self.zip.is_match(name) {
            counts.zip += 1;
        } else {
            counts.unknown += 1;
        }
    }
}

/// Counts what would be queued, by archive type. A `limit` of 0 means none.
pub async fn preview(pool: &MySqlPool, limit: u64) -> Result<ArchiveCounts> {
    let eligible = eligible(pool).await?;
    let types = ArchiveTypes::new()?;
    let mut counts = ArchiveCounts::default();
    let mut after = 0_i64;
    loop {
        let rows: Vec<(i64, String)> = sqlx::query_as(AssertSqlSafe(format!(
            "SELECT CAST(id AS SIGNED), name FROM releases WHERE {eligible} AND id > ? ORDER BY id LIMIT {CHUNK_SIZE}"
        )))
        .bind(after)
        .fetch_all(pool)
        .await?;
        let Some(tail) = rows.last() else {
            return Ok(counts);
        };
        after = tail.0;
        for (_, name) in &rows {
            types.count(&mut counts, name);
            if limit > 0 && counts.total() >= limit {
                return Ok(counts);
            }
        }
    }
}

/// Queues eligible releases, returning how many. A `limit` of 0 means none.
pub async fn queue(pool: &MySqlPool, limit: u64) -> Result<u64> {
    let eligible = eligible(pool).await?;
    let mut queued = 0;
    loop {
        let chunk = if limit > 0 {
            CHUNK_SIZE.min(limit - queued)
        } else {
            CHUNK_SIZE
        };
        let ids: Vec<i64> = sqlx::query_scalar(AssertSqlSafe(format!(
            "SELECT CAST(id AS SIGNED) FROM releases WHERE {eligible} ORDER BY id LIMIT {chunk}"
        )))
        .fetch_all(pool)
        .await?;
        if ids.is_empty() {
            return Ok(queued);
        }
        let ids = ids.iter().map(i64::to_string).collect::<Vec<_>>().join(",");
        queued += sqlx::query(AssertSqlSafe(format!(
            "UPDATE releases SET haspreview = -1, archive_retry_at = ? WHERE {eligible} AND id IN ({ids})"
        )))
        .bind(candidates::now())
        .execute(pool)
        .await?
        .rows_affected();
        if limit > 0 && queued >= limit {
            return Ok(queued);
        }
    }
}

pub async fn run(pool: &MySqlPool, limit: u64, dry_run: bool) -> Result<()> {
    if dry_run {
        let counts = preview(pool, limit).await?;
        println!("7z: {}", counts.seven_zip);
        println!("rar: {}", counts.rar);
        println!("zip: {}", counts.zip);
        println!("unknown: {}", counts.unknown);
        println!("Dry run: {} release(s) would be queued.", counts.total());
        return Ok(());
    }
    let queued = queue(pool, limit).await?;
    println!("Queued {queued} release(s) for one more archive inspection.");
    Ok(())
}
