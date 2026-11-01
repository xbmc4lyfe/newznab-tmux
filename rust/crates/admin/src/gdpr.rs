//! GDPR retention: `gdpr:purge-expired-exports`.

use std::io::ErrorKind;
use std::path::{Component, Path, PathBuf};

use anyhow::{Context, Result, bail};
use chrono::NaiveDateTime;
use nntmux_core::var_or;
use sqlx::{FromRow, MySqlPool};
use tracing::warn;

/// `GdprRequest::TYPE_EXPORT`.
pub const TYPE_EXPORT: &str = "export";

/// Laravel's storage directory, which `config/filesystems.php` put the disks under.
pub fn storage_dir() -> PathBuf {
    PathBuf::from(var_or("NNTMUX_STORAGE_DIR", "/app/storage"))
}

/// Where a Laravel disk keeps its files. Only the local disks existed for exports.
fn disk_root(storage: &Path, disk: Option<&str>) -> Result<PathBuf> {
    match disk.unwrap_or("local") {
        "" | "local" => Ok(storage.join("app/private")),
        "public" => Ok(storage.join("app/public")),
        other => bail!("disk {other} isn't a local disk"),
    }
}

/// A stored path, kept inside its disk.
fn resolve(root: &Path, stored: &str) -> Result<PathBuf> {
    let relative = Path::new(stored.trim_start_matches('/'));
    if relative
        .components()
        .any(|part| !matches!(part, Component::Normal(_)))
    {
        bail!("export path {stored} leaves its disk");
    }
    Ok(root.join(relative))
}

#[derive(FromRow)]
struct Expired {
    id: u64,
    export_disk: Option<String>,
    export_path: String,
}

/// `GdprRetentionService::purgeExpiredExports`: deletes each export file past its expiry and
/// clears its path, keeping the request as the audit record. A file already gone still clears.
/// One whose disk or path can't be resolved is left for an operator and logged.
pub async fn purge_expired_exports(
    db: &MySqlPool,
    storage: &Path,
    now: NaiveDateTime,
) -> Result<u64> {
    let expired: Vec<Expired> = sqlx::query_as(
        "SELECT id, export_disk, export_path FROM gdpr_requests \
         WHERE type = ? AND export_path IS NOT NULL AND export_expires_at IS NOT NULL \
         AND export_expires_at < ? ORDER BY id",
    )
    .bind(TYPE_EXPORT)
    .bind(now)
    .fetch_all(db)
    .await?;
    let mut purged = 0;
    for request in expired {
        let file = match disk_root(storage, request.export_disk.as_deref())
            .and_then(|root| resolve(&root, &request.export_path))
        {
            Ok(file) => file,
            Err(failure) => {
                warn!(request = request.id, error = %failure, "skipping a GDPR export");
                continue;
            }
        };
        match tokio::fs::remove_file(&file).await {
            Ok(()) => {}
            Err(failure) if failure.kind() == ErrorKind::NotFound => {}
            Err(failure) => {
                return Err(failure).with_context(|| format!("deleting {}", file.display()));
            }
        }
        sqlx::query(
            "UPDATE gdpr_requests SET export_path = NULL, export_disk = NULL, updated_at = ? \
             WHERE id = ?",
        )
        .bind(now)
        .bind(request.id)
        .execute(db)
        .await?;
        purged += 1;
    }
    Ok(purged)
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn exports_stay_inside_their_disk() {
        let storage = Path::new("/app/storage");
        let root = disk_root(storage, None).unwrap();
        assert_eq!(root, Path::new("/app/storage/app/private"));
        assert_eq!(
            resolve(
                &root,
                "gdpr/exports/user-7-20261101120000-abcdefghijkl.json"
            )
            .unwrap(),
            Path::new(
                "/app/storage/app/private/gdpr/exports/user-7-20261101120000-abcdefghijkl.json"
            )
        );
        assert!(resolve(&root, "../../../etc/passwd").is_err());
        assert!(resolve(&root, "a/./b").is_ok());
        assert!(disk_root(storage, Some("s3")).is_err());
        assert_eq!(
            disk_root(storage, Some("public")).unwrap(),
            Path::new("/app/storage/app/public")
        );
    }
}
