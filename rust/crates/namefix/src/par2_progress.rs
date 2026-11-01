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
