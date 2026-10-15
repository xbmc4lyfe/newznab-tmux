use sha2::{Digest, Sha256};
use std::path::Path;
use std::process::Command;

fn digest(path: &Path) -> String {
    format!("{:x}", Sha256::digest(std::fs::read(path).unwrap()))
}

#[test]
#[ignore = "select the actual candidate cdylib with NNTMUX_INSTALL_TEST_LIBRARY"]
fn actual_installer_replaces_verified_copy_and_preserves_previous_on_failure() {
    let source =
        std::env::var_os("NNTMUX_INSTALL_TEST_LIBRARY").expect("explicit candidate library");
    let directory = std::env::temp_dir().join(format!("nntmux-install-cli-{}", std::process::id()));
    std::fs::create_dir(&directory).unwrap();
    let destination = directory.join("library.so");
    let run = |library: &Path, manifest: Option<&Path>| {
        let mut command = Command::new(env!("CARGO_BIN_EXE_install-yenc"));
        command
            .arg("--library")
            .arg(library)
            .arg("--destination")
            .arg(&destination);
        if let Some(manifest) = manifest {
            command.arg("--build-manifest").arg(manifest);
        }
        command.output().unwrap()
    };
    let output = run(Path::new(&source), None);
    assert!(
        output.status.success(),
        "{}",
        String::from_utf8_lossy(&output.stderr)
    );
    let report: serde_json::Value = serde_json::from_slice(&output.stdout).unwrap();
    assert_eq!(report["sha256"], digest(Path::new(&source)));
    assert_eq!(digest(&destination), digest(Path::new(&source)));
    let before = std::fs::read(&destination).unwrap();
    let invalid = directory.join("invalid.so");
    std::fs::write(&invalid, b"invalid native library").unwrap();
    let output = run(&invalid, None);
    assert!(!output.status.success());
    assert_eq!(std::fs::read(&destination).unwrap(), before);
    let lock = std::fs::OpenOptions::new()
        .create(true)
        .truncate(false)
        .read(true)
        .write(true)
        .open(directory.join(".library.so.install.lock"))
        .unwrap();
    lock.try_lock().unwrap();
    let output = run(Path::new(&source), None);
    assert!(
        !output.status.success(),
        "a separate process bypassed the held install lock"
    );
    assert_eq!(std::fs::read(&destination).unwrap(), before);
    drop(lock);
    let output = run(
        Path::new(&source),
        Some(&directory.join("missing/Cargo.toml")),
    );
    assert!(!output.status.success());
    assert_eq!(std::fs::read(&destination).unwrap(), before);
    assert!(!std::fs::read_dir(&directory).unwrap().any(|entry| {
        entry
            .unwrap()
            .file_name()
            .to_string_lossy()
            .contains(".stage-")
    }));
    std::fs::remove_dir_all(directory).unwrap();
}
