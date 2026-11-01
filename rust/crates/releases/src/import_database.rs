//! `nntmux-ctl releases import-database`: copying retained filesystem NZBs into the database.
//!
//! Originals are never removed, and no fingerprint work is queued for history.

use std::io::Read;
use std::path::{Path, PathBuf};

use anyhow::{Context, Result, bail};
use nntmux_verify::in_tx;
use nntmux_verify::nzb::{self, MAX_COMPRESSED_BYTES, MAX_DECODED_BYTES, ReleaseRef};
use sqlx::MySqlPool;

#[derive(Clone, Debug)]
pub struct Options {
    /// Legacy NZB roots, searched in this order.
    pub roots: Vec<PathBuf>,
    /// Maximum releases per invocation.
    pub limit: u32,
    /// Overrides the stored resume cursor.
    pub after_id: Option<i64>,
    /// Checkpoint name.
    pub name: String,
    pub dry_run: bool,
}

#[derive(Clone, Copy, Debug, Default, PartialEq, Eq)]
pub struct Imported {
    pub copied: u64,
    pub existing: u64,
    pub cursor: i64,
}

fn valid_guid(guid: &str) -> bool {
    (1..=40).contains(&guid.len())
        && guid
            .bytes()
            .all(|byte| byte.is_ascii_alphanumeric() || byte == b'_' || byte == b'-')
}

/// Searches every supported split depth without walking the archive inventory.
fn locate(roots: &[PathBuf], guid: &str) -> Result<PathBuf> {
    if !valid_guid(guid) {
        bail!("Invalid release GUID.");
    }
    let file = format!("{guid}.nzb.gz");
    for root in roots {
        let mut directory = root.clone();
        for level in 0..=guid.len().min(32) {
            let path = directory.join(&file);
            if std::fs::symlink_metadata(&path).is_ok_and(|meta| meta.file_type().is_file()) {
                return Ok(path);
            }
            if let Some(split) = guid.get(level..=level) {
                directory.push(split);
            }
        }
    }
    bail!("Legacy NZB is missing.");
}

fn read_legacy(path: &Path) -> Result<Vec<u8>> {
    let mut body = Vec::new();
    std::fs::File::open(path)
        .and_then(|file| {
            file.take(MAX_COMPRESSED_BYTES as u64 + 1)
                .read_to_end(&mut body)
        })
        .with_context(|| format!("reading {}", path.display()))?;
    if body.len() > MAX_COMPRESSED_BYTES || nzb::decode(&body, MAX_DECODED_BYTES).is_none() {
        bail!("Legacy NZB is corrupt or exceeds the import ceiling.");
    }
    Ok(body)
}

async fn install(
    pool: &MySqlPool,
    id: i64,
    guid: &str,
    body: Option<&[u8]>,
    name: &str,
) -> Result<()> {
    in_tx!(pool, 5, |tx| {
        let current: Option<u32> =
            sqlx::query_scalar("SELECT id FROM releases WHERE id = ? AND guid = ? FOR UPDATE")
                .bind(id)
                .bind(guid)
                .fetch_optional(&mut *tx)
                .await?;
        let stored: Option<i32> =
            sqlx::query_scalar("SELECT 1 FROM release_nzb_contents WHERE guid = ? FOR UPDATE")
                .bind(guid)
                .fetch_optional(&mut *tx)
                .await?;
        if current.is_some() && stored.is_none() {
            let Some(body) = body else {
                bail!("Database source disappeared; retry this release.");
            };
            let committed: Option<String> = sqlx::query_scalar(
                "SELECT source_sha256 FROM release_archive_naming_evidence WHERE releases_id = ?",
            )
            .bind(id)
            .fetch_optional(&mut *tx)
            .await?;
            if let Some(committed) = committed {
                let matches = nzb::decode(body, MAX_DECODED_BYTES)
                    .is_some_and(|xml| nzb::digest(&xml) == committed);
                if !matches {
                    bail!(
                        "Legacy source differs from the committed generation; settle its upgrade before import."
                    );
                }
            }
            let release = ReleaseRef {
                id,
                guid: guid.to_owned(),
            };
            nzb::put_locked(&mut *tx, &release, body, None, false).await?;
        }
        sqlx::query(
            "INSERT INTO nzb_storage_imports (name, last_release_id, updated_at) VALUES (?, ?, UTC_TIMESTAMP(6)) \
             ON DUPLICATE KEY UPDATE last_release_id = VALUES(last_release_id), updated_at = VALUES(updated_at)",
        )
        .bind(name)
        .bind(id)
        .execute(&mut *tx)
        .await?;
        Ok::<_, anyhow::Error>(())
    })
}

pub async fn import(pool: &MySqlPool, options: &Options) -> Result<Imported> {
    if options.roots.is_empty() || options.roots.iter().any(|root| !root.is_dir()) {
        bail!(
            "Supply existing legacy directories with --root. Files are never removed by this command."
        );
    }
    if options.name.is_empty() || options.name.len() > 120 {
        bail!("The checkpoint name must be between 1 and 120 bytes.");
    }
    let mut cursor = match options.after_id {
        Some(after) => after.max(0),
        None => sqlx::query_scalar::<_, u32>(
            "SELECT last_release_id FROM nzb_storage_imports WHERE name = ?",
        )
        .bind(&options.name)
        .fetch_optional(pool)
        .await?
        .map_or(0, i64::from),
    };
    let limit = options.limit.clamp(1, 10_000);
    let rows: Vec<(i64, String)> = sqlx::query_as(
        "SELECT CAST(id AS SIGNED), guid FROM releases WHERE id > ? AND nzbstatus = 1 ORDER BY id LIMIT ?",
    )
    .bind(cursor)
    .bind(limit)
    .fetch_all(pool)
    .await?;
    let mut imported = Imported::default();
    for (id, guid) in rows {
        let step: Result<bool> = async {
            let existing = nzb::exists(pool, &guid).await?;
            let body = if existing {
                None
            } else {
                Some(read_legacy(&locate(&options.roots, &guid)?)?)
            };
            if !options.dry_run {
                install(pool, id, &guid, body.as_deref(), &options.name).await?;
            }
            Ok(existing)
        }
        .await;
        match step {
            Ok(true) => imported.existing += 1,
            Ok(false) => imported.copied += 1,
            Err(failure) => {
                return Err(failure.context(format!(
                    "Stopped at release {id}. Last committed release ID: {cursor}. No original files were deleted."
                )));
            }
        }
        cursor = id;
    }
    imported.cursor = cursor;
    Ok(imported)
}

pub async fn run(pool: &MySqlPool, options: &Options) -> Result<()> {
    let imported = import(pool, options).await?;
    println!(
        "{}Imported {}; already stored {}; resume after release {}.",
        if options.dry_run { "Dry run: " } else { "" },
        imported.copied,
        imported.existing,
        imported.cursor
    );
    Ok(())
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn split_depths_and_roots_are_searched_in_order() -> Result<()> {
        let base = std::env::temp_dir().join(format!("nn-locate-{}", std::process::id()));
        let (first, second) = (base.join("first"), base.join("second"));
        std::fs::create_dir_all(first.join("a/b"))?;
        std::fs::create_dir_all(&second)?;
        std::fs::write(first.join("a/b/abc.nzb.gz"), b"x")?;
        std::fs::write(second.join("abc.nzb.gz"), b"x")?;
        let roots = [first.clone(), second];
        assert_eq!(locate(&roots, "abc")?, first.join("a/b/abc.nzb.gz"));
        assert!(locate(&roots, "missing").is_err());
        assert!(locate(&roots, "../abc").is_err());
        std::fs::remove_dir_all(base)?;
        Ok(())
    }
}
