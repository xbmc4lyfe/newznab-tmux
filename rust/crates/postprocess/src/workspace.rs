//! Temp directories for additional processing: one per GUID bucket, one per worker under it, one
//! per release under that. Port of `TempWorkspaceService`.

use std::fs;
use std::io;
use std::os::unix::fs::PermissionsExt;
use std::path::{Path, PathBuf};
use std::time::{Duration, SystemTime};

use anyhow::{Context, Result, anyhow, bail};
use sha2::{Digest, Sha256};

/// The bucket directory for a GUID character or group, and under it the worker's own directory
/// when a token is given. Worker directories are named by the token's SHA-256.
pub fn ensure_main_temp_path(
    base: &Path,
    guid_char: &str,
    group_id: &str,
    worker_token: &str,
) -> Result<PathBuf> {
    let mut path = base.to_path_buf();
    if !group_id.is_empty() {
        path.push(group_id);
    } else if !guid_char.is_empty() {
        path.push(guid_char);
    }
    if !worker_token.is_empty() {
        path.push(hex::encode(Sha256::digest(worker_token.as_bytes())));
    }
    ensure_writable_directory(&path, "Additional post-processing temp path")?;
    Ok(path)
}

/// The per-release folder under a worker's directory.
pub fn create_release_temp_folder(main: &Path, guid: &str) -> Result<PathBuf> {
    assert_writable_directory(main, "Additional post-processing temp path")?;
    let path = main.join(guid);
    ensure_writable_directory(&path, "Release temp path")?;
    Ok(path)
}

/// Deletes everything under `path`, and `path` itself unless `preserve_root`. A missing path is
/// fine. Files that can't be removed are left behind, as `File::delete` did.
pub fn clear_directory(path: &Path, preserve_root: bool) {
    let Ok(meta) = fs::symlink_metadata(path) else {
        return;
    };
    if meta.is_file() || meta.file_type().is_symlink() {
        let _ = fs::remove_file(path);
        return;
    }
    if let Ok(entries) = fs::read_dir(path) {
        for entry in entries.flatten() {
            let child = entry.path();
            let is_dir = entry.file_type().is_ok_and(|kind| kind.is_dir());
            let _ = if is_dir {
                fs::remove_dir_all(&child)
            } else {
                fs::remove_file(&child)
            };
        }
    }
    if !preserve_root {
        let _ = fs::remove_dir_all(path);
    }
}

/// Removes worker directories in a bucket not touched for `stale_after_seconds`. A worker that
/// died leaves its directory behind, and nothing else ever looks there again.
pub fn prune_stale_worker_directories(bucket: &Path, stale_after_seconds: i64) -> usize {
    let Ok(entries) = fs::read_dir(bucket) else {
        return 0;
    };
    let window = Duration::from_secs(stale_after_seconds.max(1).unsigned_abs());
    let stale_before = SystemTime::now().checked_sub(window);
    let mut removed = 0;
    for entry in entries.flatten() {
        let Ok(meta) = entry.metadata() else {
            continue;
        };
        if !meta.is_dir() {
            continue;
        }
        let (Ok(modified), Some(before)) = (meta.modified(), stale_before) else {
            continue;
        };
        if modified < before && fs::remove_dir_all(entry.path()).is_ok() {
            removed += 1;
        }
    }
    removed
}

/// Every file under `path`, recursively, sorted like `File::allFiles` (Symfony Finder by name).
pub fn list_files(path: &Path) -> Result<Vec<PathBuf>> {
    let mut files = Vec::new();
    let mut pending = vec![path.to_path_buf()];
    while let Some(directory) = pending.pop() {
        let entries = fs::read_dir(&directory)
            .with_context(|| format!("ERROR: Could not open temp dir: {}", directory.display()))?;
        for entry in entries {
            let entry = entry?;
            if entry.file_type()?.is_dir() {
                pending.push(entry.path());
            } else {
                files.push(entry.path());
            }
        }
    }
    files.sort();
    Ok(files)
}

/// True when a file can be created in `path` (PHP's `is_writable` on a directory).
pub fn directory_is_writable(path: &Path) -> bool {
    tempfile::tempfile_in(path).is_ok()
}

/// True when `path`, or the nearest parent that exists, is writable.
pub fn nearest_existing_directory_is_writable(path: &Path) -> bool {
    path.ancestors()
        .find(|candidate| !candidate.as_os_str().is_empty() && candidate.is_dir())
        .is_some_and(directory_is_writable)
}

fn ensure_writable_directory(path: &Path, label: &str) -> Result<()> {
    if path.is_dir() {
        repair_directory_if_possible(path);
    } else {
        make_directory(path).map_err(|failure| {
            anyhow!(
                "{label} \"{}\" could not be created: {failure}",
                path.display()
            )
        })?;
    }
    assert_writable_directory(path, label)
}

fn make_directory(path: &Path) -> io::Result<()> {
    fs::create_dir_all(path)?;
    fs::set_permissions(path, fs::Permissions::from_mode(0o777))
}

fn repair_directory_if_possible(path: &Path) {
    if directory_is_writable(path) {
        return;
    }
    let _ = fs::set_permissions(path, fs::Permissions::from_mode(0o777));
    if directory_is_writable(path) {
        return;
    }
    let Some(parent) = path.parent() else {
        return;
    };
    if !parent.is_dir() || !directory_is_writable(parent) {
        return;
    }
    let _ = fs::remove_dir_all(path);
    if !path.is_dir() {
        let _ = make_directory(path);
    }
}

fn assert_writable_directory(path: &Path, label: &str) -> Result<()> {
    if !path.is_dir() {
        bail!("{label} \"{}\" is not a directory", path.display());
    }
    if !directory_is_writable(path) {
        bail!(
            "{label} \"{}\" is not writable by the worker user. Check TEMP_UNRAR_PATH ownership and permissions.",
            path.display()
        );
    }
    Ok(())
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn worker_directories_nest_under_the_bucket_and_clear() -> Result<()> {
        let root = tempfile::tempdir()?;
        let main = ensure_main_temp_path(root.path(), "a", "", "token")?;
        assert_eq!(
            main,
            root.path()
                .join("a")
                .join("3c469e9d6c5875d37a43f353d4f88e61fcf812c66eee3457465a40b0da4153e0")
        );
        let release = create_release_temp_folder(&main, "guid")?;
        fs::create_dir_all(release.join("nested"))?;
        fs::write(release.join("nested/file"), b"x")?;
        fs::write(release.join("top"), b"x")?;
        assert_eq!(
            list_files(&release)?,
            [release.join("nested/file"), release.join("top")]
        );

        clear_directory(&release, true);
        assert!(release.is_dir());
        assert_eq!(list_files(&release)?, Vec::<PathBuf>::new());
        clear_directory(&main, false);
        assert!(!main.exists());
        Ok(())
    }

    #[test]
    fn group_wins_over_the_guid_bucket() -> Result<()> {
        let root = tempfile::tempdir()?;
        let main = ensure_main_temp_path(root.path(), "a", "17", "")?;
        assert_eq!(main, root.path().join("17"));
        Ok(())
    }

    #[test]
    fn pruning_keeps_fresh_directories() -> Result<()> {
        let root = tempfile::tempdir()?;
        fs::create_dir(root.path().join("fresh"))?;
        assert_eq!(prune_stale_worker_directories(root.path(), 3600), 0);
        assert!(root.path().join("fresh").is_dir());
        Ok(())
    }

    #[test]
    fn an_unwritable_path_under_a_file_is_an_error() -> Result<()> {
        let root = tempfile::tempdir()?;
        let file = root.path().join("file");
        fs::write(&file, b"x")?;
        let failure = ensure_main_temp_path(&file, "a", "", "").unwrap_err();
        assert!(failure.to_string().contains("could not be created"));
        Ok(())
    }

    #[test]
    fn nearest_parent_decides_writability() -> Result<()> {
        let root = tempfile::tempdir()?;
        assert!(nearest_existing_directory_is_writable(
            &root.path().join("a/b/c")
        ));
        Ok(())
    }
}
