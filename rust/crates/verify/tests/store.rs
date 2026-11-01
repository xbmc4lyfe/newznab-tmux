//! Manifest rows, the capacity ledger and NZB bodies against a real database.

use anyhow::Result;
use nntmux_testkit::TestDb;
use nntmux_verify::config::VerificationConfig;
use nntmux_verify::in_tx;
use nntmux_verify::ledger;
use nntmux_verify::manifest::{self, Manifest, ManifestFile, PreparedManifest, Segment};
use nntmux_verify::nzb::{self, ReleaseRef};
use sqlx::MySqlPool;

fn config() -> VerificationConfig {
    VerificationConfig::for_tests(
        std::env::temp_dir().join(format!("nntmux-verify-{}", std::process::id())),
    )
}

fn sample(subject: &str) -> Manifest {
    Manifest {
        identity: "ab".repeat(20),
        subject: subject.to_owned(),
        fromname: "poster".to_owned(),
        date: Some("2026-10-31 12:30:45".to_owned()),
        groups_id: 1,
        groups: vec!["alt.binaries.test".to_owned()],
        files: vec![ManifestFile {
            key: "00".repeat(16),
            subject: "file.rar".to_owned(),
            totalparts: 1,
            totalparts_inferred: false,
            segments: vec![Segment {
                number: 1,
                messageid: "one@example.com".to_owned(),
                bytes: 100,
            }],
        }],
        ..Manifest::default()
    }
}

async fn insert_release(pool: &MySqlPool, guid: &str) -> Result<i64> {
    let id = sqlx::query(
        "INSERT INTO releases (name, searchname, groups_id, guid, leftguid, size, adddate, postdate) \
         VALUES ('n', 's', 1, ?, ?, 1, NOW(), NOW())",
    )
    .bind(guid)
    .bind(&guid[..1])
    .execute(pool)
    .await?
    .last_insert_id();
    Ok(id as i64)
}

#[tokio::test]
async fn manifests_round_trip_and_stay_until_unreferenced() -> Result<()> {
    let Some(db) = TestDb::create().await? else {
        return Ok(());
    };
    let config = config();
    let manifest = sample("Round trip");
    let prepared = PreparedManifest::new(&manifest)?;
    let mut tx = db.pool.begin().await?;
    let name = manifest::write_prepared(&mut tx, &prepared).await?;
    // Writing the same manifest again validates the stored row and keeps it.
    assert_eq!(manifest::write_prepared(&mut tx, &prepared).await?, name);
    assert_eq!(
        manifest::size(&mut tx, &config, &name).await?,
        prepared.compressed.len() as i64
    );
    assert_eq!(
        manifest::read_current(&mut tx, &config, &name).await?,
        manifest
    );
    tx.commit().await?;
    assert_eq!(manifest::read(&db.pool, &config, &name).await?, manifest);

    sqlx::query(
        "INSERT INTO release_verification_candidates (identity, groups_id, manifest_path, manifest_bytes, \
         manifest_hash, last_unique_at, next_attempt_at) VALUES ('a', 1, ?, 1, ?, NOW(), NOW())",
    )
    .bind(&name)
    .bind(&name[..64])
    .execute(&db.pool)
    .await?;
    manifest::delete_if_unreferenced(&db.pool, &config, &name).await?;
    assert_eq!(manifest::read(&db.pool, &config, &name).await?, manifest);

    sqlx::query("DELETE FROM release_verification_candidates")
        .execute(&db.pool)
        .await?;
    manifest::delete_if_unreferenced(&db.pool, &config, &name).await?;
    assert!(manifest::read(&db.pool, &config, &name).await.is_err());
    db.drop_database().await
}

#[tokio::test]
async fn a_row_under_the_wrong_name_is_refused() -> Result<()> {
    let Some(db) = TestDb::create().await? else {
        return Ok(());
    };
    let config = config();
    let prepared = PreparedManifest::new(&sample("One"))?;
    let other = PreparedManifest::new(&sample("Other"))?;
    sqlx::query("INSERT INTO release_verification_manifests (name, body, bytes) VALUES (?, ?, ?)")
        .bind(&prepared.name)
        .bind(&other.compressed)
        .bind(other.compressed.len() as u64)
        .execute(&db.pool)
        .await?;
    assert!(
        manifest::read(&db.pool, &config, &prepared.name)
            .await
            .is_err()
    );
    let mut tx = db.pool.begin().await?;
    assert!(manifest::write_prepared(&mut tx, &prepared).await.is_err());
    tx.rollback().await?;
    db.drop_database().await
}

#[tokio::test]
async fn the_ledger_refuses_growth_past_capacity() -> Result<()> {
    let Some(db) = TestDb::create().await? else {
        return Ok(());
    };
    let mut config = config();
    config.pending_bytes = 100;
    sqlx::query("INSERT INTO release_verification_state (id, pending_bytes) VALUES (1, 0) ON DUPLICATE KEY UPDATE pending_bytes = 0")
        .execute(&db.pool)
        .await?;
    let mut conn = db.pool.acquire().await?;
    assert!(ledger::fits(&mut *conn, &config, 100).await?);
    assert!(!ledger::fits(&mut *conn, &config, 101).await?);
    assert!(ledger::adjust(&mut conn, &config, 90).await?);
    assert!(!ledger::adjust(&mut conn, &config, 11).await?);
    assert!(
        ledger::adjust(&mut conn, &config, -200).await.is_err(),
        "releasing more than is held is accounting drift"
    );
    assert!(ledger::adjust(&mut conn, &config, -90).await?);
    let pending: u64 =
        sqlx::query_scalar("SELECT pending_bytes FROM release_verification_state WHERE id = 1")
            .fetch_one(&mut *conn)
            .await?;
    assert_eq!(pending, 0);
    drop(conn);
    db.drop_database().await
}

#[tokio::test]
async fn publication_locks_take_stripes_and_fall_back_to_the_exclusive_row() -> Result<()> {
    let Some(db) = TestDb::create().await? else {
        return Ok(());
    };
    let mut first = db.pool.begin().await?;
    ledger::enter_publication(&mut first, &["size:1".to_owned()]).await?;
    // A publication with another key shares the row; a keyless one waits for both.
    let mut second = db.pool.begin().await?;
    ledger::enter_publication(&mut second, &["size:2".to_owned()]).await?;
    second.rollback().await?;
    first.rollback().await?;
    let mut keyless = db.pool.begin().await?;
    ledger::enter_publication(&mut keyless, &[]).await?;
    keyless.rollback().await?;
    db.drop_database().await
}

#[tokio::test]
async fn installing_an_nzb_records_evidence_and_queues_fingerprint_work() -> Result<()> {
    let Some(db) = TestDb::create().await? else {
        return Ok(());
    };
    let guid = "c0ffee00-0000-0000-0000-000000000001";
    let id = insert_release(&db.pool, guid).await?;
    let release = ReleaseRef {
        id,
        guid: guid.to_owned(),
    };
    let body = nzb::compress(b"<nzb>one</nzb>")?;
    let digest = in_tx!(db.pool, 1, |tx| {
        nzb::put_locked(tx, &release, &body, None, true).await
    })?;
    assert_eq!(digest, nzb::digest(b"<nzb>one</nzb>"));
    assert!(nzb::exists(&db.pool, guid).await?);
    assert_eq!(
        nzb::read(&db.pool, guid).await?.as_deref(),
        Some(&b"<nzb>one</nzb>"[..])
    );
    let job: (String, String) = sqlx::query_as(
        "SELECT source_digest, status FROM release_fingerprint_jobs WHERE release_id = ?",
    )
    .bind(id)
    .fetch_one(&db.pool)
    .await?;
    assert_eq!(job, (digest.clone(), "pending".to_owned()));
    let evidence: String = sqlx::query_scalar(
        "SELECT source_sha256 FROM release_archive_naming_evidence WHERE releases_id = ?",
    )
    .bind(id)
    .fetch_one(&db.pool)
    .await?;
    assert_eq!(evidence, digest);
    let generation: String =
        sqlx::query_scalar("SELECT generation FROM release_nzb_contents WHERE guid = ?")
            .bind(guid)
            .fetch_one(&db.pool)
            .await?;

    // A compare-and-swap against the wrong digest changes nothing.
    let replacement = nzb::compress(b"<nzb>two</nzb>")?;
    let refused = in_tx!(db.pool, 1, |tx| {
        nzb::put_locked(tx, &release, &replacement, Some("wrong"), true).await
    });
    assert!(refused.is_err());
    assert_eq!(
        nzb::read(&db.pool, guid).await?.as_deref(),
        Some(&b"<nzb>one</nzb>"[..])
    );

    // A new generation replaces the body and restarts its in-flight work.
    sqlx::query("UPDATE release_fingerprint_jobs SET status = 'processing', lease_token = 'held' WHERE release_id = ?")
        .bind(id)
        .execute(&db.pool)
        .await?;
    let second = in_tx!(db.pool, 1, |tx| {
        nzb::put_locked(tx, &release, &replacement, Some(&digest), true).await
    })?;
    let job: (String, String, Option<String>) = sqlx::query_as(
        "SELECT source_digest, status, lease_token FROM release_fingerprint_jobs WHERE release_id = ?",
    )
    .bind(id)
    .fetch_one(&db.pool)
    .await?;
    assert_eq!(job, (second.clone(), "pending".to_owned(), None));
    let new_generation: String =
        sqlx::query_scalar("SELECT generation FROM release_nzb_contents WHERE guid = ?")
            .bind(guid)
            .fetch_one(&db.pool)
            .await?;
    assert_ne!(generation, new_generation);

    // Tampered bytes fail the digest check rather than read as a missing NZB.
    sqlx::query("UPDATE release_nzb_contents SET source_digest = ? WHERE guid = ?")
        .bind("0".repeat(64))
        .bind(guid)
        .execute(&db.pool)
        .await?;
    assert!(nzb::read(&db.pool, guid).await.is_err());

    assert!(nzb::delete(&db.pool, guid).await?);
    assert!(!nzb::exists(&db.pool, guid).await?);
    db.drop_database().await
}

#[tokio::test]
async fn legacy_files_are_imported_only_while_referenced() -> Result<()> {
    let Some(db) = TestDb::create().await? else {
        return Ok(());
    };
    let directory = std::env::temp_dir().join(format!("nntmux-import-{}", db.name));
    std::fs::create_dir_all(directory.join("manifests"))?;
    let config = VerificationConfig::for_tests(directory.clone());
    let kept = PreparedManifest::new(&sample("Referenced"))?;
    let orphan = PreparedManifest::new(&sample("Orphan"))?;
    std::fs::write(
        manifest::legacy_path(&config, &kept.name)?,
        &kept.compressed,
    )?;
    std::fs::write(
        manifest::legacy_path(&config, &orphan.name)?,
        &orphan.compressed,
    )?;
    sqlx::query(
        "INSERT INTO release_verification_candidates (identity, groups_id, manifest_path, manifest_bytes, \
         manifest_hash, last_unique_at, next_attempt_at) VALUES ('a', 1, ?, 1, ?, NOW(), NOW())",
    )
    .bind(&kept.name)
    .bind(&kept.name[..64])
    .execute(&db.pool)
    .await?;

    // Readable from its file before the import.
    assert_eq!(
        manifest::read(&db.pool, &config, &kept.name).await?,
        sample("Referenced")
    );
    let names = [
        kept.name.clone(),
        orphan.name.clone(),
        "not-a-manifest".to_owned(),
    ];
    let counts = manifest::import_legacy_files(&db.pool, &config, &names, true).await?;
    assert_eq!(
        (counts.imported, counts.unreferenced, counts.invalid),
        (1, 1, 1)
    );
    assert!(!manifest::legacy_path(&config, &kept.name)?.exists());
    assert!(manifest::legacy_path(&config, &orphan.name)?.exists());
    assert_eq!(
        manifest::read(&db.pool, &config, &kept.name).await?,
        sample("Referenced")
    );

    let report = manifest::import_referenced(&db.pool, &config, 10, 0, 0, false).await?;
    assert_eq!(report.counts.already, 1);
    assert_eq!(report.last_candidate_id, 1);
    std::fs::remove_dir_all(directory)?;
    db.drop_database().await
}

#[tokio::test]
async fn barriers_and_pauses_exclude_workers() -> Result<()> {
    use nntmux_verify::locks::{self, WorkerLocks};
    let Some(db) = TestDb::create().await? else {
        return Ok(());
    };
    let config = config();
    let mut worker = WorkerLocks::new(&db.pool, &config).await?;
    let mut other = WorkerLocks::new(&db.pool, &config).await?;
    assert!(worker.start(0).await?);
    assert!(worker.held().await);
    assert!(worker.admitting().await?);
    assert!(
        !other.try_exclusive().await?,
        "a shared holder refuses an exclusive one"
    );
    assert!(
        !other.drain(0.1).await?,
        "a drain times out while a worker runs"
    );
    worker.close().await;
    assert!(other.drain(0.1).await?);
    assert!(
        !worker.start(0).await?,
        "a drain's gate refuses new workers"
    );
    other.close().await;

    assert!(worker.start(0).await?);
    locks::pause(&db.pool, "token", "deployment").await?;
    assert!(!worker.admitting().await?, "a durable pause stops claiming");
    let mut late = WorkerLocks::new(&db.pool, &config).await?;
    assert!(!late.start(0).await?);
    locks::unpause(&db.pool, None, Some("deployment")).await?;
    assert!(worker.admitting().await?);
    worker.close().await;
    db.drop_database().await
}

#[tokio::test]
async fn an_admission_gives_its_lock_back_and_refuses_while_a_reset_runs() -> Result<()> {
    use nntmux_verify::locks::{self, Admission, LockSession};
    let Some(db) = TestDb::create().await? else {
        return Ok(());
    };
    let mut resets = LockSession::open(&db.pool).await?;
    let admission = Admission::enter(&db.pool).await?;
    assert!(
        resets.exclusive_group("reset").await?.is_none(),
        "a running admission holds a reset out"
    );
    admission.leave().await?;
    let gate = resets
        .exclusive_group("reset")
        .await?
        .expect("nothing holds it now");
    assert!(
        Admission::enter(&db.pool).await.is_err(),
        "a reset holds admissions out"
    );
    resets.release(gate).await;

    // A task dropped mid-admission doesn't leave its stripe held by a pooled connection.
    drop(Admission::enter(&db.pool).await?);
    let mut freed = false;
    for _ in 0..100 {
        if let Some(gate) = resets.exclusive_group("reset").await? {
            resets.release(gate).await;
            freed = true;
            break;
        }
        tokio::time::sleep(std::time::Duration::from_millis(50)).await;
    }
    assert!(freed);

    locks::pause(&db.pool, "t", "reset").await?;
    assert!(
        Admission::enter(&db.pool).await.is_err(),
        "a lost reset's pause holds admissions out"
    );
    locks::unpause(&db.pool, Some("t"), None).await?;
    assert!(resets.exclusive_group("reset").await?.is_some());
    db.drop_database().await
}
