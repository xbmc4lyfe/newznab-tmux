#![cfg(any(target_os = "linux", target_os = "macos"))]

use sha2::{Digest, Sha256};
use std::process::Command;

#[test]
#[ignore = "explicit full-ABI cdylib required: NNTMUX_INSTALL_TEST_LIBRARY"]
fn verified_experimental_library_report_denies_current_adoption() {
    // Fixture input only: the execution owner binds this full16+24 experimental
    // artifact to its source, build protocol, platform, and SHA-256.
    // An incomplete-export library is not an alternative for this test.
    let candidate = std::path::PathBuf::from(
        std::env::var_os("NNTMUX_INSTALL_TEST_LIBRARY")
            .expect("set NNTMUX_INSTALL_TEST_LIBRARY to the explicit experimental cdylib"),
    )
    .canonicalize()
    .expect("explicit experimental cdylib must exist");
    assert!(candidate.is_file(), "candidate must be a regular file");
    let before = std::fs::read(&candidate).expect("read candidate before verification");
    let digest = format!("{:x}", Sha256::digest(&before));

    // This invokes the real outer CLI. Its verifier owns its private loader
    // snapshot and bounded known-answer checks; no evidence graph is synthesized.
    let output = Command::new(env!("CARGO_BIN_EXE_qualify-yenc"))
        .arg("verify")
        .arg("--library")
        .arg(&candidate)
        .output()
        .expect("spawn actual qualify-yenc verify command");
    eprintln!(
        "qualify-yenc verify status: {}\nstdout: {}\nstderr: {}",
        output.status,
        String::from_utf8_lossy(&output.stdout),
        String::from_utf8_lossy(&output.stderr)
    );
    assert!(
        output.status.success(),
        "diagnostic verification must succeed before testing adoption disposition"
    );
    let report: serde_json::Value =
        serde_json::from_slice(&output.stdout).expect("successful CLI must emit JSON");
    assert_eq!(report["path"], serde_json::json!(candidate));
    assert_eq!(report["sha256"], digest);
    assert_eq!(report["version"], 0x0103);
    assert_eq!(report["rapidyenc_exports"].as_array().unwrap().len(), 16);
    assert_eq!(report["metadata_exports"].as_array().unwrap().len(), 24);
    assert_eq!(
        std::fs::read(&candidate).expect("read candidate after verification"),
        before,
        "diagnostic verification must preserve the supplied artifact"
    );

    // Historical correctness success cannot authorize adoption under the new
    // common-path and representative uncommon-p99 policy, including caller costs.
    assert_eq!(
        report["adoption_eligibility"]["eligible"], false,
        "successful historical diagnostic must explicitly deny current adoption"
    );
    assert_eq!(
        report["adoption_eligibility"]["policy"],
        "common-and-uncommon-p99-1.5x"
    );
    assert_eq!(
        report["adoption_eligibility"]["reason"],
        "current-policy evidence unavailable"
    );
}
