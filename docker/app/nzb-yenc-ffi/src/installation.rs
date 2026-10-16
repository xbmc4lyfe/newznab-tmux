//! Deny public replacement installation until current-policy authorization exists.

#[cfg(test)]
use crate::tooling::file_sha256;
use serde_json::Value;
#[cfg(test)]
use serde_json::json;
#[cfg(test)]
use std::fs::{File, OpenOptions};
#[cfg(test)]
use std::io::Write;
use std::path::Path;

pub fn install(
    source: &Path,
    destination: &Path,
    verify: impl FnOnce(&Path) -> Result<Value, String>,
) -> Result<Value, String> {
    install_after(source, destination, || Ok(()), verify)
}

pub fn install_after(
    _source: &Path,
    _destination: &Path,
    _prepare: impl FnOnce() -> Result<(), String>,
    _verify: impl FnOnce(&Path) -> Result<Value, String>,
) -> Result<Value, String> {
    Err("current-policy adoption authorization unavailable; installation denied".into())
}

#[cfg(test)]
fn install_mechanics(
    source: &Path,
    destination: &Path,
    verify: impl FnOnce(&Path) -> Result<Value, String>,
) -> Result<Value, String> {
    install_mechanics_after(source, destination, || Ok(()), verify)
}

#[cfg(test)]
fn install_mechanics_after(
    source: &Path,
    destination: &Path,
    prepare: impl FnOnce() -> Result<(), String>,
    verify: impl FnOnce(&Path) -> Result<Value, String>,
) -> Result<Value, String> {
    use std::sync::atomic::{AtomicUsize, Ordering};
    static NEXT: AtomicUsize = AtomicUsize::new(0);
    let parent = destination
        .parent()
        .filter(|p| !p.as_os_str().is_empty())
        .unwrap_or(Path::new("."));
    std::fs::create_dir_all(parent).map_err(|e| e.to_string())?;
    let parent = parent.canonicalize().map_err(|e| e.to_string())?;
    let name = destination
        .file_name()
        .ok_or("destination needs filename")?;
    let destination = parent.join(name);
    if destination.is_dir() {
        return Err("destination is a directory".into());
    }
    let lock_path = parent.join(format!(".{}.install.lock", name.to_string_lossy()));
    let lock = OpenOptions::new()
        .create(true)
        .truncate(false)
        .read(true)
        .write(true)
        .open(lock_path)
        .map_err(|e| e.to_string())?;
    lock.try_lock()
        .map_err(|e| format!("another installer holds destination lock: {e}"))?;
    // Keep the stable lock inode after unlocking: unlinking would let a third
    // process bypass a contender already waiting on the old inode.
    prepare()?;
    let mut input = File::open(source).map_err(|e| e.to_string())?;
    if !input.metadata().map_err(|e| e.to_string())?.is_file() {
        return Err("source is not a regular file".into());
    }
    let staged_path = parent.join(format!(
        ".{}.stage-{}-{}",
        name.to_string_lossy(),
        std::process::id(),
        NEXT.fetch_add(1, Ordering::Relaxed)
    ));
    let mut staged = OpenOptions::new()
        .write(true)
        .create_new(true)
        .open(&staged_path)
        .map_err(|e| e.to_string())?;
    struct OwnedStage(std::path::PathBuf);
    impl Drop for OwnedStage {
        fn drop(&mut self) {
            let _ = std::fs::remove_file(&self.0);
        }
    }
    let owned = OwnedStage(staged_path);
    std::io::copy(&mut input, &mut staged).map_err(|e| e.to_string())?;
    staged.flush().map_err(|e| e.to_string())?;
    staged.sync_all().map_err(|e| e.to_string())?;
    drop(staged);
    let digest = file_sha256(&owned.0)?;
    let verified = verify(&owned.0)?;
    if verified["sha256"].as_str() != Some(digest.as_str()) || file_sha256(&owned.0)? != digest {
        return Err("staged artifact changed or verifier identified different bytes".into());
    }
    std::fs::rename(&owned.0, &destination).map_err(|e| e.to_string())?;
    File::open(&parent)
        .and_then(|directory| directory.sync_all())
        .map_err(|e| e.to_string())?;
    Ok(
        json!({"status":"installed", "destination":destination, "sha256":digest, "verification":verified}),
    )
}

#[cfg(test)]
mod tests {
    use super::*;
    use std::sync::atomic::{AtomicUsize, Ordering};

    fn workspace() -> std::path::PathBuf {
        static NEXT: AtomicUsize = AtomicUsize::new(0);
        let directory = std::env::temp_dir().join(format!(
            "nntmux-install-{}-{}",
            std::process::id(),
            NEXT.fetch_add(1, Ordering::Relaxed)
        ));
        std::fs::create_dir(&directory).unwrap();
        directory
    }

    #[test]
    fn current_policy_install_preserves_existing_incumbent_without_callbacks() {
        assert_current_policy_denial(false, true);
    }

    #[test]
    fn current_policy_install_does_not_create_destination_parent() {
        assert_current_policy_denial(false, false);
    }

    #[test]
    fn current_policy_install_after_preserves_existing_incumbent_without_callbacks() {
        assert_current_policy_denial(true, true);
    }

    #[test]
    fn current_policy_install_after_does_not_create_destination_parent() {
        assert_current_policy_denial(true, false);
    }

    fn assert_current_policy_denial(after: bool, incumbent: bool) {
        use std::cell::Cell;
        use std::collections::BTreeSet;

        let directory = workspace();
        let source = directory.join("source.so");
        let parent = if incumbent {
            directory.clone()
        } else {
            directory.join("not-created").join("nested")
        };
        let destination = parent.join("installed.so");
        std::fs::write(&source, b"unqualified candidate").unwrap();
        if incumbent {
            std::fs::write(&destination, b"incumbent").unwrap();
        }
        let entries = || -> BTreeSet<_> {
            std::fs::read_dir(&directory)
                .unwrap()
                .map(|entry| entry.unwrap().file_name())
                .collect()
        };
        let before = entries();
        let prepared = Cell::new(0);
        let verified = Cell::new(0);
        let prepare = || {
            prepared.set(prepared.get() + 1);
            Ok(())
        };
        let verify = |staged: &Path| {
            verified.set(verified.get() + 1);
            // A byte digest is not current-policy performance authorization.
            Ok(json!({"sha256":file_sha256(staged)?}))
        };
        let result = if after {
            install_after(&source, &destination, prepare, verify)
        } else {
            install(&source, &destination, verify)
        };
        let after_entries = entries();
        let parent_created = !incumbent && parent.exists();
        let destination_bytes = std::fs::read(&destination).ok();
        let source_bytes = std::fs::read(&source).unwrap();
        std::fs::remove_dir_all(directory).unwrap();

        assert!(
            matches!(&result, Err(error) if error.contains("current-policy")),
            "public installation needs current-policy denial, got {result:?}"
        );
        assert_eq!(prepared.get(), 0, "prepare/build callback ran");
        assert_eq!(verified.get(), 0, "verification callback ran");
        assert_eq!(
            after_entries, before,
            "lock, stage or directory was created"
        );
        assert!(!parent_created, "destination parent was created");
        assert_eq!(source_bytes, b"unqualified candidate");
        assert_eq!(
            destination_bytes.as_deref(),
            if incumbent {
                Some(b"incumbent".as_slice())
            } else {
                None
            }
        );
    }

    #[test]
    fn success_verifies_staged_copy_and_retains_source() {
        let directory = workspace();
        let source = directory.join("source.so");
        let destination = directory.join("installed.so");
        std::fs::write(&source, b"candidate").unwrap();
        std::fs::write(&destination, b"old").unwrap();
        let report = install_mechanics(&source, &destination, |staged| {
            assert_ne!(staged, source);
            assert_ne!(staged, destination);
            assert_eq!(std::fs::read(staged).unwrap(), b"candidate");
            assert_eq!(std::fs::read(&destination).unwrap(), b"old");
            std::fs::write(&source, b"changed-after-copy").unwrap();
            Ok(json!({"sha256":file_sha256(staged)?}))
        })
        .unwrap();
        assert_eq!(std::fs::read(&destination).unwrap(), b"candidate");
        assert_eq!(std::fs::read(&source).unwrap(), b"changed-after-copy");
        assert_eq!(report["sha256"], file_sha256(&destination).unwrap());
        std::fs::remove_dir_all(directory).unwrap();
    }

    #[test]
    fn failed_verification_preserves_old_and_cleans_owned_temp() {
        let directory = workspace();
        let source = directory.join("source.so");
        let destination = directory.join("installed.so");
        std::fs::write(&source, b"candidate").unwrap();
        std::fs::write(&destination, b"old").unwrap();
        assert!(install_mechanics(&source, &destination, |_| Err("bad ABI".into())).is_err());
        assert_eq!(std::fs::read(&destination).unwrap(), b"old");
        assert!(!std::fs::read_dir(&directory).unwrap().any(|entry| {
            entry
                .unwrap()
                .file_name()
                .to_string_lossy()
                .contains(".stage-")
        }));
        std::fs::remove_dir_all(directory).unwrap();
    }

    #[test]
    fn concurrent_installer_is_rejected_without_replacement() {
        let directory = workspace();
        let source = directory.join("source.so");
        let destination = directory.join("installed.so");
        std::fs::write(&source, b"candidate").unwrap();
        std::fs::write(&destination, b"old").unwrap();
        install_mechanics(&source, &destination, |staged| {
            assert!(
                install_mechanics(&source, &destination, |_| panic!("must not verify")).is_err()
            );
            assert_eq!(std::fs::read(&destination).unwrap(), b"old");
            Ok(json!({"sha256":file_sha256(staged)?}))
        })
        .unwrap();
        std::fs::remove_dir_all(directory).unwrap();
    }

    #[test]
    fn staged_mutation_does_not_install_unverified_bytes() {
        let directory = workspace();
        let source = directory.join("source.so");
        let destination = directory.join("installed.so");
        std::fs::write(&source, b"candidate").unwrap();
        std::fs::write(&destination, b"old").unwrap();
        assert!(
            install_mechanics(&source, &destination, |staged| {
                let digest = file_sha256(staged)?;
                std::fs::write(staged, b"replaced").unwrap();
                Ok(json!({"sha256":digest}))
            })
            .is_err()
        );
        assert_eq!(std::fs::read(&destination).unwrap(), b"old");
        std::fs::remove_dir_all(directory).unwrap();
    }
}
