//! `par2_rename_progress`: how far a release's PAR2 probing has got.

use anyhow::Result;
use sqlx::MySqlPool;

use crate::release::Snapshot;

/// Clears completed work only while the committed identity is still terminal. A reset or
/// replacement release may already have new candidate buffers by the time this runs.
pub async fn forget_completed_snapshot(db: &MySqlPool, snapshot: &Snapshot) -> Result<bool> {
    let mut tx = db.begin().await?;
    #[allow(clippy::type_complexity)]
    let row: Option<(String, Option<String>, Option<String>, i64, i32, i32)> = sqlx::query_as(
        "SELECT guid, name, searchname, CAST(predb_id AS SIGNED), isrenamed, proc_par2 FROM releases WHERE id = ? FOR UPDATE",
    )
    .bind(snapshot.id)
    .fetch_optional(&mut *tx)
    .await?;
    let Some((guid, name, searchname, predb_id, isrenamed, proc_par2)) = row else {
        return Ok(false);
    };
    if guid != snapshot.guid
        || name != snapshot.name
        || searchname != snapshot.searchname
        || (predb_id <= 0 && isrenamed != 1 && proc_par2 != 1)
    {
        return Ok(false);
    }
    sqlx::query("DELETE FROM par2_rename_progress WHERE releases_id = ?")
        .bind(snapshot.id)
        .execute(&mut *tx)
        .await?;
    tx.commit().await?;
    Ok(true)
}

/// A release's outer PAR2 cursor: which candidates are done and which must be retried.
#[derive(Clone, Debug, PartialEq, Eq)]
pub struct RenameProgress {
    pub signature: String,
    pub offset: i64,
    pub retry_indexes: Vec<i64>,
}

/// `Par2RenameProgress::get`. A stored retry list keeps only its integers.
pub async fn get(db: &MySqlPool, release_id: i64) -> Result<Option<RenameProgress>> {
    let row: Option<(String, i64, String)> = sqlx::query_as(
        "SELECT signature, CAST(`offset` AS SIGNED), retry_indexes FROM par2_rename_progress WHERE releases_id = ?",
    )
    .bind(release_id)
    .fetch_optional(db)
    .await?;
    let Some((signature, offset, retry)) = row else {
        return Ok(None);
    };
    let retry_indexes = match serde_json::from_str::<serde_json::Value>(&retry)? {
        serde_json::Value::Array(items) => items.iter().filter_map(|v| v.as_i64()).collect(),
        _ => Vec::new(),
    };
    Ok(Some(RenameProgress {
        signature,
        offset,
        retry_indexes,
    }))
}

/// `Par2RenameProgress::save` inside the caller's transaction: records the cursor unless the
/// release has since finished or been renamed, in which case the progress is dropped and the
/// answer is false. A different signature replaces the whole progress, candidates included.
pub async fn save_in(
    conn: &mut sqlx::MySqlConnection,
    release_id: i64,
    signature: &str,
    offset: i64,
    retry_indexes: &[i64],
) -> Result<bool> {
    let release: Option<(i64, i64, i64)> = sqlx::query_as(
        "SELECT CAST(isrenamed AS SIGNED), CAST(proc_par2 AS SIGNED), CAST(predb_id AS SIGNED) FROM releases WHERE id = ? FOR UPDATE",
    )
    .bind(release_id)
    .fetch_optional(&mut *conn)
    .await?;
    let finished = match release {
        None => true,
        Some((isrenamed, proc_par2, predb_id)) => isrenamed == 1 || proc_par2 == 1 || predb_id > 0,
    };
    if finished {
        sqlx::query("DELETE FROM par2_rename_progress WHERE releases_id = ?")
            .bind(release_id)
            .execute(&mut *conn)
            .await?;
        return Ok(false);
    }
    let previous: Option<String> =
        sqlx::query_scalar("SELECT signature FROM par2_rename_progress WHERE releases_id = ?")
            .bind(release_id)
            .fetch_optional(&mut *conn)
            .await?;
    if previous.is_some_and(|previous| previous != signature) {
        sqlx::query("DELETE FROM par2_rename_progress WHERE releases_id = ?")
            .bind(release_id)
            .execute(&mut *conn)
            .await?;
    }
    let retry = crate::par2_json::Json::Arr(
        retry_indexes
            .iter()
            .map(|index| crate::par2_json::Json::Int(*index))
            .collect(),
    )
    .encode();
    sqlx::query(
        "INSERT INTO par2_rename_progress (releases_id, signature, `offset`, retry_indexes, updated_at) \
         VALUES (?, ?, ?, ?, NOW()) \
         ON DUPLICATE KEY UPDATE signature = VALUES(signature), `offset` = VALUES(`offset`), \
         retry_indexes = VALUES(retry_indexes), updated_at = VALUES(updated_at)",
    )
    .bind(release_id)
    .bind(signature)
    .bind(offset)
    .bind(retry)
    .execute(&mut *conn)
    .await?;
    Ok(true)
}

/// `Par2RenameProgress::save` in its own transaction.
pub async fn save(
    db: &MySqlPool,
    release_id: i64,
    signature: &str,
    offset: i64,
    retry_indexes: &[i64],
) -> Result<bool> {
    let mut tx = db.begin().await?;
    let saved = save_in(&mut tx, release_id, signature, offset, retry_indexes).await?;
    tx.commit().await?;
    Ok(saved)
}

/// `Par2RenameProgress::forget`: clears progress for a release that finished. A failure is
/// logged and left for the next completion to clear.
pub async fn forget(db: &MySqlPool, release_id: i64) {
    if let Err(error) = sqlx::query("DELETE FROM par2_rename_progress WHERE releases_id = ?")
        .bind(release_id)
        .execute(db)
        .await
    {
        tracing::warn!(release_id, %error, "Could not clear completed PAR2 probe progress");
    }
}
