//! `nntmux-ctl releases nzb-clean`: legacy NZB files left on disk.
//!
//! NZBs live in the database, so this only ever concerns files from before the import. A missing
//! file says nothing about a release, and an orphan isn't proof either: PHP's guard refused every
//! orphan deletion until publication ownership could be qualified, so those are reported and kept.
//! A staged generation belongs to a filesystem-era upgrade, which only PHP could settle, so those
//! are kept too. Only stale temporary files are ever deleted.

use std::fs::Metadata;
use std::path::{Path, PathBuf};
use std::time::{Duration, SystemTime};

use anyhow::{Result, bail};
use nntmux_core::pcre::Pcre;
use sqlx::MySqlPool;
use tracing::warn;

#[derive(Clone, Debug)]
pub struct Options {
    pub root: PathBuf,
    pub not_in_db: bool,
    pub not_on_disk: bool,
    pub temps: bool,
    pub temp_age_hours: u64,
    pub delete: bool,
}

#[derive(Clone, Copy, Debug, Default, PartialEq, Eq)]
pub struct Orphans {
    pub checked: u64,
    pub orphans: u64,
}

#[derive(Clone, Copy, Debug, Default, PartialEq, Eq)]
pub struct Temporaries {
    pub deleted: u64,
    pub kept: u64,
}

/// Every plain file under a root. Symlinks aren't followed, and an unreadable directory is logged
/// and skipped.
struct Walk {
    directories: Vec<PathBuf>,
    files: Vec<(PathBuf, Metadata)>,
}

impl Walk {
    fn new(root: &Path) -> Self {
        Self {
            directories: vec![root.to_owned()],
            files: Vec::new(),
        }
    }
}

impl Iterator for Walk {
    type Item = (PathBuf, Metadata);

    fn next(&mut self) -> Option<Self::Item> {
        loop {
            if let Some(file) = self.files.pop() {
                return Some(file);
            }
            let directory = self.directories.pop()?;
            let entries = match std::fs::read_dir(&directory) {
                Ok(entries) => entries,
                Err(failure) => {
                    warn!(directory = %directory.display(), error = %failure, "skipping a directory");
                    continue;
                }
            };
            for entry in entries.flatten() {
                let path = entry.path();
                let Ok(meta) = std::fs::symlink_metadata(&path) else {
                    continue;
                };
                if meta.is_dir() {
                    self.directories.push(path);
                } else if meta.is_file() {
                    self.files.push((path, meta));
                }
            }
        }
    }
}

struct Names {
    temporary: Pcre,
    staged: Pcre,
}

impl Names {
    fn new() -> Result<Self> {
        Ok(Self {
            temporary: Pcre::new(r"/\.nzb\.gz\.tmp\.\d+\.[0-9a-f]{12}$/")?,
            staged: Pcre::new(r"/^(.+)\.nzb\.gz\.c\d+-[0-9a-f]{16}\.next$/D")?,
        })
    }
}

fn file_name(path: &Path) -> String {
    path.file_name()
        .map(|name| name.to_string_lossy().into_owned())
        .unwrap_or_default()
}

/// The guid in `<guid>.nzb.gz`, matched case-insensitively as PHP's `stristr` did.
fn guid_of(name: &str) -> Option<&str> {
    let end = name.to_ascii_lowercase().find(".nzb.gz")?;
    Some(&name[..end]).filter(|guid| !guid.is_empty())
}

/// Reports NZB files with no release row. Nothing is deleted.
pub async fn orphans(pool: &MySqlPool, root: &Path) -> Result<Orphans> {
    let names = Names::new()?;
    let mut found = Orphans::default();
    for (path, _) in Walk::new(root) {
        let name = file_name(&path);
        if names.temporary.is_match(name.as_bytes()) {
            continue;
        }
        if names.staged.is_match(name.as_bytes()) {
            found.checked += 1;
            println!("Kept staged NZB generation for review: {}", path.display());
            continue;
        }
        let Some(guid) = guid_of(&name) else {
            continue;
        };
        found.checked += 1;
        let exists: Option<i32> =
            sqlx::query_scalar("SELECT 1 FROM releases WHERE guid = ? LIMIT 1")
                .bind(guid)
                .fetch_optional(pool)
                .await?;
        if exists.is_none() {
            found.orphans += 1;
            println!("Retained orphan file pending publication ownership verification: {name}");
        }
    }
    Ok(found)
}

/// Deletes temporary files older than the cutoff, or lists them on a dry run. Staged generations
/// are kept.
pub fn temporaries(root: &Path, age: Duration, delete: bool) -> Result<Temporaries> {
    let names = Names::new()?;
    let cutoff = SystemTime::now()
        .checked_sub(age)
        .unwrap_or(SystemTime::UNIX_EPOCH);
    let mut outcome = Temporaries::default();
    for (path, meta) in Walk::new(root) {
        let name = file_name(&path);
        if names.staged.is_match(name.as_bytes()) {
            outcome.kept += 1;
            println!("Kept staged NZB generation for review: {}", path.display());
            continue;
        }
        if !names.temporary.is_match(name.as_bytes()) || meta.modified()? > cutoff {
            continue;
        }
        if !delete {
            outcome.deleted += 1;
            println!("Would delete stale temporary NZB: {}", path.display());
            continue;
        }
        match std::fs::remove_file(&path) {
            Ok(()) => outcome.deleted += 1,
            Err(failure) => {
                outcome.kept += 1;
                println!(
                    "Could not delete stale temporary NZB: {}: {failure}",
                    path.display()
                );
            }
        }
    }
    Ok(outcome)
}

pub async fn run(pool: &MySqlPool, options: &Options) -> Result<()> {
    if options.not_on_disk {
        bail!(
            "Filesystem absence cannot qualify database releases for deletion; unimported sources must be retained."
        );
    }
    if !options.not_in_db && !options.temps {
        bail!("You must specify at least one option. See: --help");
    }
    if !options.root.is_dir() {
        bail!(
            "{} is not a directory. Set PATH_TO_NZBS.",
            options.root.display()
        );
    }
    if options.not_in_db {
        let found = orphans(pool, &options.root).await?;
        println!("Checked: {} / Orphans: {}", found.checked, found.orphans);
    }
    if options.temps {
        let age = Duration::from_secs(options.temp_age_hours.max(1).saturating_mul(3600));
        let outcome = temporaries(&options.root, age, options.delete)?;
        println!(
            "{} stale temporary NZBs: {} deleted, {} kept.",
            if options.delete {
                "Cleaned"
            } else {
                "Checked (dry run)"
            },
            outcome.deleted,
            outcome.kept
        );
    }
    Ok(())
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn only_stale_temporary_files_are_deleted() -> Result<()> {
        let root = std::env::temp_dir().join(format!("nn-clean-{}", std::process::id()));
        std::fs::create_dir_all(root.join("a"))?;
        let temporary = root.join("a/x.nzb.gz.tmp.123.0123456789ab");
        let staged = root.join("a/x.nzb.gz.c9-0123456789abcdef.next");
        let canonical = root.join("a/x.nzb.gz");
        for path in [&temporary, &staged, &canonical] {
            std::fs::write(path, b"x")?;
        }

        // A file newer than the age is left alone.
        let fresh = temporaries(&root, Duration::from_secs(3600), true)?;
        assert_eq!(
            fresh,
            Temporaries {
                deleted: 0,
                kept: 1
            }
        );
        assert!(temporary.exists());

        // A dry run lists it and leaves it.
        let dry = temporaries(&root, Duration::ZERO, false)?;
        assert_eq!(
            dry,
            Temporaries {
                deleted: 1,
                kept: 1
            }
        );
        assert!(temporary.exists());

        let done = temporaries(&root, Duration::ZERO, true)?;
        assert_eq!(
            done,
            Temporaries {
                deleted: 1,
                kept: 1
            }
        );
        assert!(!temporary.exists() && staged.exists() && canonical.exists());
        std::fs::remove_dir_all(root)?;
        Ok(())
    }

    #[test]
    fn the_guid_comes_from_the_canonical_name() {
        assert_eq!(guid_of("abc.nzb.gz"), Some("abc"));
        assert_eq!(guid_of("ABC.NZB.GZ"), Some("ABC"));
        assert_eq!(guid_of(".nzb.gz"), None);
        assert_eq!(guid_of("readme.txt"), None);
    }
}
