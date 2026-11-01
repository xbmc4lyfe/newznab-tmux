//! Removing a release row and everything derived from it, shared by release maintenance and audits.

use std::path::PathBuf;

use anyhow::Result;
use nntmux_core::var_or;
use sqlx::{MySqlConnection, MySqlPool};

use crate::context::Verify;
use crate::nzb;

fn covers_root() -> PathBuf {
    PathBuf::from(var_or("COVERS_PATH", "storage/covers"))
}

/// The preview and sample files a release may have on disk.
pub fn release_files(guid: &str) -> Vec<PathBuf> {
    let root = covers_root();
    let mut files = vec![
        root.join("audiosample").join(format!("{guid}.ogg")),
        root.join("video").join(format!("{guid}.ogv")),
    ];
    for extension in ["webp", "jpg", "jpeg"] {
        files.push(
            root.join("preview")
                .join(format!("{guid}_thumb.{extension}")),
        );
        files.push(
            root.join("sample")
                .join(format!("{guid}_thumb.{extension}")),
        );
    }
    files
}

/// Removes a release's preview and sample files. Files already gone are fine.
pub fn delete_release_files(guid: &str) -> Result<()> {
    for path in release_files(guid) {
        match std::fs::remove_file(&path) {
            Ok(()) => {}
            Err(error) if error.kind() == std::io::ErrorKind::NotFound => {}
            Err(error) => return Err(error.into()),
        }
    }
    Ok(())
}

/// Queues a search document refresh. The search role drains `search_index_failures`, retrying as it
/// goes, so this survives a worker dying before its update reaches Manticore.
pub async fn request_search_update(conn: &mut MySqlConnection, release: i64) -> Result<()> {
    request_search(conn, release, "upsert").await
}

/// Queues a search document removal.
pub async fn request_search_delete(conn: &mut MySqlConnection, release: i64) -> Result<()> {
    request_search(conn, release, "delete").await
}

async fn request_search(conn: &mut MySqlConnection, release: i64, operation: &str) -> Result<()> {
    let now = crate::candidates::now();
    sqlx::query(
        "INSERT INTO search_index_failures (release_id, operation, attempts, next_attempt_at, created_at, updated_at) \
         VALUES (?, ?, 0, ?, ?, ?) ON DUPLICATE KEY UPDATE operation = VALUES(operation), attempts = 0, \
         last_error = NULL, next_attempt_at = VALUES(next_attempt_at), resolved_at = NULL, updated_at = VALUES(updated_at)",
    )
    .bind(release)
    .bind(operation)
    .bind(now)
    .bind(now)
    .bind(now)
    .execute(&mut *conn)
    .await?;
    Ok(())
}

/// Deletes a release: its NZB, its files, its search document, then its row, whose foreign keys take
/// the rest.
pub async fn delete_single(verify: &Verify, id: i64, guid: &str) -> Result<()> {
    delete_single_on(&verify.db, id, guid).await
}

pub async fn delete_single_on(pool: &MySqlPool, id: i64, guid: &str) -> Result<()> {
    nzb::delete(pool, guid).await?;
    delete_release_files(guid)?;
    let mut conn = pool.acquire().await?;
    request_search_delete(&mut conn, id).await?;
    sqlx::query("DELETE FROM releases WHERE guid = ?")
        .bind(guid)
        .execute(&mut *conn)
        .await?;
    Ok(())
}
