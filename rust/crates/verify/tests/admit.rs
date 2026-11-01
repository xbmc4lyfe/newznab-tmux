//! Admitting imported manifests against a real database.

#[allow(dead_code)]
mod support;

use anyhow::Result;
use nntmux_verify::candidates::{AdmissionDeferred, enqueue_with_admission};
use nntmux_verify::manifest::{Manifest, ManifestFile, Segment};
use support::Harness;

fn manifest(identity: char, passwords: Option<Vec<String>>) -> Manifest {
    Manifest {
        identity: identity.to_string().repeat(40),
        subject: "Some Subject".to_owned(),
        fromname: "poster".to_owned(),
        groups_id: 1,
        expected_files: 1,
        metadata_source: Some("import".to_owned()),
        passwords,
        files: vec![ManifestFile {
            key: "k".to_owned(),
            subject: "file.rar (1/1)".to_owned(),
            totalparts: 1,
            totalparts_inferred: false,
            segments: vec![Segment {
                number: 1,
                messageid: format!("{identity}@example.com"),
                bytes: 100,
            }],
        }],
        ..Manifest::default()
    }
}

async fn row(harness: &Harness, identity: &str) -> Result<(String, i64, String)> {
    Ok(sqlx::query_as(
        "SELECT status, CAST(version AS SIGNED), manifest_path FROM release_verification_candidates WHERE identity = ?",
    )
    .bind(identity)
    .fetch_one(&harness.db())
    .await?)
}

#[tokio::test]
async fn admission_reserves_capacity_and_replays_are_cheap() -> Result<()> {
    let Some(harness) = Harness::start().await? else {
        return Ok(());
    };
    let plain = manifest('a', None);
    let first = enqueue_with_admission(&harness.verify, &plain)
        .await?
        .expect("admitted");
    assert_eq!(first.version, 1);
    let reserved = harness.pending_bytes().await?;
    assert!(reserved > 0);
    let (status, _, path) = row(&harness, &plain.identity).await?;
    assert_eq!(status, "ready");
    assert_eq!(first.manifest_hash, path[..64]);

    // The same identity again changes nothing.
    let replay = enqueue_with_admission(&harness.verify, &plain)
        .await?
        .expect("accepted");
    assert_eq!(replay, first);
    assert_eq!(harness.pending_bytes().await?, reserved);
    harness.finish().await
}

#[tokio::test]
async fn invalid_passwords_are_refused_before_anything_is_stored() -> Result<()> {
    let Some(harness) = Harness::start().await? else {
        return Ok(());
    };
    let invalid = manifest('b', Some(vec!["x".to_owned(); 65]));
    assert!(
        enqueue_with_admission(&harness.verify, &invalid)
            .await?
            .is_none()
    );
    assert_eq!(harness.count("release_verification_candidates").await?, 0);
    harness.finish().await
}

#[tokio::test]
async fn passwords_enrich_an_identical_import_only() -> Result<()> {
    let Some(harness) = Harness::start().await? else {
        return Ok(());
    };
    let plain = manifest('c', None);
    enqueue_with_admission(&harness.verify, &plain).await?;

    let with_password = manifest('c', Some(vec!["secret".to_owned()]));
    let enriched = enqueue_with_admission(&harness.verify, &with_password)
        .await?
        .expect("enriched");
    assert_eq!(enriched.version, 2, "a new revision fences running workers");
    assert_eq!(harness.count("release_verification_manifests").await?, 1);

    // The same password is a replay, and another one is refused.
    let same = enqueue_with_admission(&harness.verify, &with_password)
        .await?
        .expect("replay");
    assert_eq!(same.version, 2);
    let other = manifest('c', Some(vec!["other".to_owned()]));
    assert!(
        enqueue_with_admission(&harness.verify, &other)
            .await?
            .is_none()
    );

    // A different document under the identity is too.
    let mut changed = manifest('c', Some(vec!["secret2".to_owned()]));
    changed.subject = "Changed".to_owned();
    assert!(
        enqueue_with_admission(&harness.verify, &changed)
            .await?
            .is_none()
    );
    harness.finish().await
}

#[tokio::test]
async fn a_full_ledger_defers_and_stores_nothing() -> Result<()> {
    let Some(harness) = Harness::start_with(|config| config.pending_bytes = 1).await? else {
        return Ok(());
    };
    let failure = enqueue_with_admission(&harness.verify, &manifest('d', None))
        .await
        .expect_err("deferred");
    assert!(matches!(
        failure.downcast_ref::<AdmissionDeferred>(),
        Some(AdmissionDeferred::Capacity)
    ));
    assert_eq!(harness.count("release_verification_candidates").await?, 0);
    assert_eq!(harness.count("release_verification_manifests").await?, 0);
    harness.finish().await
}
