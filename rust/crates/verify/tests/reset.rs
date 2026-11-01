//! Clearing verification state around a database reset.

#[allow(dead_code)]
mod support;

use anyhow::{Result, bail};
use nntmux_verify::locks::{self, Admission, LockSession};
use nntmux_verify::reset;
use support::Harness;

async fn count(harness: &Harness, table: &str) -> Result<i64> {
    Ok(
        sqlx::query_scalar(sqlx::AssertSqlSafe(format!("SELECT COUNT(*) FROM {table}")))
            .fetch_one(&harness.db())
            .await?,
    )
}

#[tokio::test]
async fn a_reset_clears_this_hosts_state_and_keeps_other_hosts_scratch() -> Result<()> {
    let Some(harness) = Harness::start().await? else {
        return Ok(());
    };
    let db = harness.db();
    sqlx::query("UPDATE release_verification_state SET pending_bytes = 5, audit_cursor = 9")
        .execute(&db)
        .await?;
    sqlx::query(
        "INSERT INTO release_verification_scratch (candidate_id, version, host) VALUES (1, 1, ''), (2, 1, 'remote')",
    )
    .execute(&db)
    .await?;
    sqlx::query(
        "INSERT INTO releases (name, searchname, groups_id, guid, leftguid, size, adddate, postdate, late_article_candidate_id) \
         VALUES ('n', 's', 1, 'r0000000', 'r', 1, UTC_TIMESTAMP(), UTC_TIMESTAMP(), 77)",
    )
    .execute(&db)
    .await?;
    let storage = harness.verify.config.storage_path.clone();
    std::fs::create_dir_all(storage.join("candidate-1"))?;
    std::fs::write(storage.join("candidate-1/x"), b"x")?;
    std::fs::write(storage.join("stray"), b"x")?;
    std::fs::write(storage.join(".gitignore"), b"x")?;

    let seen = reset::run(&harness.verify, || async {
        // The fence is up while the caller's reset runs.
        locks::paused(&db, Some("reset")).await
    })
    .await?;
    assert!(seen);
    assert!(!locks::paused(&db, None).await?);

    let (pending, cursor): (u64, u64) =
        sqlx::query_as("SELECT pending_bytes, audit_cursor FROM release_verification_state")
            .fetch_one(&db)
            .await?;
    assert_eq!((pending, cursor), (0, 0));
    let rows: Vec<(String, bool)> =
        sqlx::query_as("SELECT host, terminal FROM release_verification_scratch")
            .fetch_all(&db)
            .await?;
    assert_eq!(rows, vec![("remote".to_owned(), true)]);
    let fenced: Option<u64> = sqlx::query_scalar("SELECT late_article_candidate_id FROM releases")
        .fetch_one(&db)
        .await?;
    assert_eq!(fenced, None);
    assert!(storage.join(".gitignore").exists());
    assert!(!storage.join("candidate-1").exists() && !storage.join("stray").exists());
    assert_eq!(
        count(&harness, "release_verification_scratch_budget").await?,
        1
    );
    std::fs::remove_dir_all(storage)?;
    harness.finish().await
}

#[tokio::test]
async fn a_reset_refuses_running_work_and_releases_its_fence_on_failure() -> Result<()> {
    let Some(harness) = Harness::start().await? else {
        return Ok(());
    };
    let db = harness.db();

    // An admission in progress holds it off.
    let admission = Admission::enter(&db).await?;
    assert!(
        reset::run(&harness.verify, || async { Ok(()) })
            .await
            .is_err()
    );
    admission.leave().await?;

    // So does a worker.
    let mut worker = LockSession::open(&db).await?;
    let held = worker.shared("worker").await?;
    assert!(held.is_some());
    assert!(
        reset::run(&harness.verify, || async { Ok(()) })
            .await
            .is_err()
    );
    if let Some(held) = held {
        worker.release(held).await;
    }

    // And a deployment pause, which it leaves in place.
    locks::pause(&db, "deploy", "deployment").await?;
    assert!(
        reset::run(&harness.verify, || async { Ok(()) })
            .await
            .is_err()
    );
    assert!(locks::paused(&db, Some("deployment")).await?);
    locks::unpause(&db, Some("deploy"), None).await?;

    // A failed reset takes its own fence down and reports the failure.
    let failed = reset::run(&harness.verify, || async {
        bail!("reset failed");
        #[allow(unreachable_code)]
        Ok(())
    })
    .await;
    assert!(failed.is_err());
    assert!(!locks::paused(&db, None).await?);
    assert_eq!(count(&harness, "release_verification_state").await?, 1);

    // An admission fails while a reset's durable pause remains from a lost session.
    locks::pause(&db, "lost", "reset").await?;
    assert!(Admission::enter(&db).await.is_err());
    locks::unpause(&db, None, Some("reset")).await?;
    Admission::enter(&db).await?.leave().await?;
    harness.finish().await
}
