//! `clean:directories`: clears what extraction left behind in the temp directories.

use std::path::{Path, PathBuf};
use std::time::{Duration, SystemTime};

use anyhow::Result;
use nntmux_core::var_or;

const MAX_AGE: Duration = Duration::from_secs(4 * 60 * 60);

fn directories() -> [PathBuf; 2] {
    [
        PathBuf::from(var_or("TEMP_UNRAR_PATH", "resources/tmp/unrar")),
        PathBuf::from(var_or("TEMP_UNZIP_PATH", "resources/tmp/unzip")),
    ]
}

/// Deletes files older than `max_age`, then any subdirectory left empty. `root` itself stays, and a
/// missing `root` has nothing to clean. Returns the files deleted.
pub fn clean(root: &Path, max_age: Duration, now: SystemTime) -> Result<u64> {
    let entries = match std::fs::read_dir(root) {
        Ok(entries) => entries,
        Err(failure) if failure.kind() == std::io::ErrorKind::NotFound => return Ok(0),
        Err(failure) => return Err(failure.into()),
    };
    let mut deleted = 0;
    for entry in entries {
        let entry = entry?;
        let kind = entry.file_type()?;
        if kind.is_dir() {
            deleted += clean(&entry.path(), max_age, now)?;
            // Only succeeds if the directory is now empty, which is what's wanted.
            let _ = std::fs::remove_dir(entry.path());
        } else {
            let modified = entry.metadata()?.modified()?;
            if now.duration_since(modified).is_ok_and(|age| age > max_age) {
                std::fs::remove_file(entry.path())?;
                deleted += 1;
            }
        }
    }
    Ok(deleted)
}

pub async fn clean_all() -> Result<u64> {
    tokio::task::spawn_blocking(|| {
        let now = SystemTime::now();
        let mut deleted = 0;
        for directory in directories() {
            deleted += clean(&directory, MAX_AGE, now)?;
        }
        Ok(deleted)
    })
    .await?
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn removes_old_files_and_emptied_directories_only() -> Result<()> {
        let dir = tempfile::tempdir()?;
        std::fs::create_dir_all(dir.path().join("old/nested"))?;
        std::fs::create_dir_all(dir.path().join("fresh"))?;
        for name in ["old/nested/a", "old/b", "top", "fresh/c"] {
            std::fs::write(dir.path().join(name), "x")?;
        }
        // Everything was written just now, so an hour from now only a shorter limit sees it as old.
        let later = SystemTime::now() + Duration::from_secs(3600);
        assert_eq!(clean(dir.path(), Duration::from_secs(4 * 3600), later)?, 0);
        assert!(dir.path().join("old/nested/a").exists());

        std::fs::remove_file(dir.path().join("fresh/c"))?;
        assert_eq!(clean(dir.path(), Duration::from_secs(60), later)?, 3);
        assert!(dir.path().is_dir());
        assert!(!dir.path().join("old").exists());
        assert!(!dir.path().join("fresh").exists());
        assert_eq!(clean(&dir.path().join("missing"), MAX_AGE, later)?, 0);
        Ok(())
    }
}
