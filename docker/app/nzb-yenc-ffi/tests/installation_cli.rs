use std::process::Command;

#[test]
fn current_policy_cli_denies_missing_source_before_creating_destination_parent() {
    let directory =
        std::env::temp_dir().join(format!("nntmux-install-policy-cli-{}", std::process::id()));
    std::fs::create_dir(&directory).unwrap();
    let parent = directory.join("not-created").join("nested");
    let destination = parent.join("library.so");
    let output = Command::new(env!("CARGO_BIN_EXE_install-yenc"))
        .arg("--library")
        .arg(directory.join("missing-source.so"))
        .arg("--destination")
        .arg(&destination)
        .output()
        .unwrap();
    let parent_created = directory.join("not-created").exists();
    let destination_created = destination.exists();
    std::fs::remove_dir_all(directory).unwrap();

    assert!(!output.status.success());
    assert!(output.stdout.is_empty(), "denial emitted a success report");
    assert!(
        String::from_utf8_lossy(&output.stderr).contains("current-policy"),
        "expected policy denial before source access, got {}",
        String::from_utf8_lossy(&output.stderr)
    );
    assert!(
        !parent_created,
        "CLI created destination parent before denial"
    );
    assert!(!destination_created);
}

#[test]
#[cfg(unix)]
fn current_policy_cli_denies_build_manifest_without_invoking_cargo() {
    use std::collections::BTreeSet;
    use std::os::unix::fs::PermissionsExt;

    let directory = std::env::temp_dir().join(format!(
        "nntmux-install-policy-build-cli-{}",
        std::process::id()
    ));
    std::fs::create_dir(&directory).unwrap();
    let source = directory.join("unqualified.so");
    let destination = directory.join("library.so");
    std::fs::write(&source, b"unqualified candidate").unwrap();
    std::fs::write(&destination, b"incumbent").unwrap();
    let fake_bin = directory.join("fake-bin");
    std::fs::create_dir(&fake_bin).unwrap();
    let fake_cargo = fake_bin.join("cargo");
    std::fs::write(&fake_cargo, b"#!/bin/sh\n: > \"$0.called\"\nexit 99\n").unwrap();
    std::fs::set_permissions(&fake_cargo, std::fs::Permissions::from_mode(0o755)).unwrap();
    let entries = || -> BTreeSet<_> {
        std::fs::read_dir(&directory)
            .unwrap()
            .map(|entry| entry.unwrap().file_name())
            .collect()
    };
    let before = entries();
    let output = Command::new(env!("CARGO_BIN_EXE_install-yenc"))
        .arg("--library")
        .arg(&source)
        .arg("--destination")
        .arg(&destination)
        .arg("--build-manifest")
        .arg(directory.join("missing/Cargo.toml"))
        .env("PATH", &fake_bin)
        .output()
        .unwrap();
    let cargo_called = fake_bin.join("cargo.called").exists();
    let after = entries();
    let destination_bytes = std::fs::read(&destination).unwrap();
    let source_bytes = std::fs::read(&source).unwrap();
    std::fs::remove_dir_all(directory).unwrap();

    assert!(!output.status.success());
    assert!(output.stdout.is_empty());
    assert!(
        String::from_utf8_lossy(&output.stderr).contains("current-policy"),
        "expected policy denial before build, got {}",
        String::from_utf8_lossy(&output.stderr)
    );
    assert!(
        !cargo_called,
        "--build-manifest invoked Cargo before denial"
    );
    assert_eq!(after, before, "lock, stage or directory was created");
    assert_eq!(destination_bytes, b"incumbent");
    assert_eq!(source_bytes, b"unqualified candidate");
}
