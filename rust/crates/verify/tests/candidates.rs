//! Candidate admission, late articles, expiry and the claim queue against a real database and Redis.

mod support;

use anyhow::Result;
use nntmux_verify::candidates::{self, FinishedAttempt};
use nntmux_verify::locks::WorkerLocks;
use nntmux_verify::manifest;
use nntmux_verify::prepare;
use support::Harness;

type CandidateSummary = (u64, String, Option<u64>, Option<u64>, String);

#[tokio::test]
async fn archiving_moves_headers_into_a_ready_candidate() -> Result<()> {
    let Some(harness) = Harness::start().await? else {
        return Ok(());
    };
    let id = harness
        .collection("aa", 1, &[("f1", 2, &[1, 2])], 3)
        .await?;
    candidates::archive(&harness.verify, id).await?;

    let (status, path, version): (String, String, u32) = sqlx::query_as(
        "SELECT status, manifest_path, version FROM release_verification_candidates WHERE source_identity = ?",
    )
    .bind(harness.source("aa"))
    .fetch_one(&harness.db())
    .await?;
    assert_eq!((status.as_str(), version), ("ready", 1));
    let manifest = manifest::read(&harness.db(), &harness.verify.config, &path).await?;
    assert_eq!(manifest.files.len(), 1);
    assert_eq!(manifest.files[0].segments.len(), 2);
    assert_eq!(manifest.groups, vec!["alt.binaries.test".to_owned()]);

    assert_eq!(harness.count("collections").await?, 0);
    assert_eq!(harness.count("binaries").await?, 0);
    assert_eq!(harness.count("parts").await?, 0);
    let size = manifest::PreparedManifest::new(&manifest)?.compressed.len() as u64;
    assert_eq!(harness.pending_bytes().await?, size);
    harness.finish().await
}

#[tokio::test]
async fn an_upload_missing_articles_waits_and_merges_later_ones() -> Result<()> {
    let Some(harness) = Harness::start().await? else {
        return Ok(());
    };
    let first = harness
        .collection("bb", 2, &[("f1", 3, &[1, 2])], 3)
        .await?;
    candidates::archive(&harness.verify, first).await?;
    let (status, reason): (String, Option<String>) = sqlx::query_as(
        "SELECT status, reason FROM release_verification_candidates WHERE source_identity = ?",
    )
    .bind(harness.source("bb"))
    .fetch_one(&harness.db())
    .await?;
    assert_eq!(status, "waiting_upload");
    assert!(reason.is_some());

    // The rest of the file and the other file arrive under the same hash.
    let second = harness
        .collection("bb", 2, &[("f1", 3, &[3]), ("f2", 1, &[1])], 3)
        .await?;
    candidates::archive(&harness.verify, second).await?;
    let (status, version, path): (String, u32, String) = sqlx::query_as(
        "SELECT status, version, manifest_path FROM release_verification_candidates WHERE source_identity = ?",
    )
    .bind(harness.source("bb"))
    .fetch_one(&harness.db())
    .await?;
    assert_eq!((status.as_str(), version), ("ready", 2));
    let manifest = manifest::read(&harness.db(), &harness.verify.config, &path).await?;
    assert_eq!(manifest.files.len(), 2);
    assert_eq!(harness.count("release_verification_candidates").await?, 1);
    // The superseded manifest was deleted and the ledger holds only the new one.
    assert_eq!(harness.count("release_verification_manifests").await?, 1);
    let size = manifest::PreparedManifest::new(&manifest)?.compressed.len() as u64;
    assert_eq!(harness.pending_bytes().await?, size);
    harness.finish().await
}

#[tokio::test]
async fn conflicting_headers_mark_the_candidate_rejected_once_claimed() -> Result<()> {
    let Some(harness) = Harness::start().await? else {
        return Ok(());
    };
    let first = harness.collection("cc", 1, &[("f1", 2, &[1])], 3).await?;
    candidates::archive(&harness.verify, first).await?;
    // The same part with another message ID.
    let second = harness
        .collection_with_ids("cc", 1, "other", &[("f1", 2, &[1])], 3)
        .await?;
    candidates::archive(&harness.verify, second).await?;
    let (status, pending, reason): (String, bool, Option<String>) = sqlx::query_as(
        "SELECT status, header_check_pending, reason FROM release_verification_candidates WHERE source_identity = ?",
    )
    .bind(harness.source("cc"))
    .fetch_one(&harness.db())
    .await?;
    assert_eq!(status, "unknown");
    assert!(pending);
    assert_eq!(
        reason.as_deref(),
        Some("Conflicting header identities require resolution.")
    );
    harness.finish().await
}

#[tokio::test]
async fn a_full_ledger_keeps_the_original_headers() -> Result<()> {
    let Some(harness) = Harness::start_with(|config| config.pending_bytes = 10).await? else {
        return Ok(());
    };
    let id = harness.collection("dd", 1, &[("f1", 1, &[1])], 3).await?;
    let error = candidates::archive(&harness.verify, id).await.unwrap_err();
    assert!(error.to_string().contains("capacity is full"), "{error:#}");
    assert_eq!(harness.count("collections").await?, 1);
    assert_eq!(harness.count("parts").await?, 1);
    assert_eq!(harness.count("release_verification_candidates").await?, 0);
    assert_eq!(harness.count("release_verification_manifests").await?, 0);
    assert_eq!(harness.pending_bytes().await?, 0);
    harness.finish().await
}

#[tokio::test]
async fn an_over_budget_upload_is_deferred_until_the_budget_rises() -> Result<()> {
    let Some(harness) = Harness::start_with(|config| config.metadata_bytes = 10).await? else {
        return Ok(());
    };
    let id = harness.collection("ee", 1, &[("f1", 1, &[1])], 3).await?;
    let kept = candidates::archive_each(&harness.verify, &[id]).await;
    let outcome = kept.get(&id).expect("kept");
    assert!(outcome.error.is_some());
    assert!(outcome.retry_after.is_some());
    let attempts: u32 = sqlx::query_scalar(
        "SELECT attempts FROM release_verification_oversized_collections WHERE collection_id = ?",
    )
    .bind(id)
    .fetch_one(&harness.db())
    .await?;
    assert_eq!(attempts, 1);

    // Waiting out the backoff: not read again, and no second failure recorded.
    let kept = candidates::archive_each(&harness.verify, &[id]).await;
    assert!(kept.get(&id).expect("kept").error.is_none());
    assert_eq!(harness.count("parts").await?, 1);

    // A raised budget ends the deferral at once.
    let raised = harness.with_config(|config| config.metadata_bytes = 1_000_000);
    assert!(candidates::unlocked_ids(&raised, &[id]).await.contains(&id));
    let kept = candidates::archive_each(&raised, &[id]).await;
    assert!(kept.is_empty(), "{kept:?}");
    assert_eq!(harness.count("parts").await?, 0);
    assert_eq!(harness.count("release_verification_candidates").await?, 1);
    candidates::forget_deferrals(&raised, &[id]).await;
    assert_eq!(
        harness
            .count("release_verification_oversized_collections")
            .await?,
        0
    );
    harness.finish().await
}

#[tokio::test]
async fn a_filtered_replay_is_deleted_without_a_new_candidate() -> Result<()> {
    let Some(harness) = Harness::start().await? else {
        return Ok(());
    };
    let id = harness.collection("ff", 1, &[("f1", 1, &[1])], 3).await?;
    candidates::archive(&harness.verify, id).await?;
    sqlx::query(
        "UPDATE release_verification_candidates SET status = 'filtered', manifest_path = '', manifest_bytes = 0",
    )
    .execute(&harness.db())
    .await?;
    let replay = harness.collection("ff", 1, &[("f1", 1, &[1])], 3).await?;
    candidates::archive(&harness.verify, replay).await?;
    assert_eq!(harness.count("collections").await?, 0);
    assert_eq!(harness.count("release_verification_candidates").await?, 1);
    harness.finish().await
}

#[tokio::test]
async fn late_articles_for_a_published_partial_start_an_upgrading_generation() -> Result<()> {
    let Some(harness) = Harness::start().await? else {
        return Ok(());
    };
    let first = harness.collection("ab", 1, &[("f1", 2, &[1])], 3).await?;
    candidates::archive(&harness.verify, first).await?;
    let (candidate, path): (u64, String) =
        sqlx::query_as("SELECT id, manifest_path FROM release_verification_candidates")
            .fetch_one(&harness.db())
            .await?;
    let release = harness.published_partial(candidate, &path).await?;

    let late = harness.collection("ab", 1, &[("f1", 2, &[2])], 3).await?;
    candidates::archive(&harness.verify, late).await?;

    let rows: Vec<CandidateSummary> = sqlx::query_as(
        "SELECT id, status, release_id, upgrades_release_id, manifest_path FROM release_verification_candidates ORDER BY id",
    )
    .fetch_all(&harness.db())
    .await?;
    assert_eq!(rows.len(), 2);
    // The published row gave up its inventory, and the new generation holds it and upgrades the release.
    assert_eq!(rows[0].2, Some(release));
    assert_eq!(rows[0].4, "");
    assert_eq!(rows[1].3, Some(release));
    assert_eq!(rows[1].1, "ready");
    let pointer: Option<u64> =
        sqlx::query_scalar("SELECT late_article_candidate_id FROM releases WHERE id = ?")
            .bind(release)
            .fetch_one(&harness.db())
            .await?;
    assert_eq!(pointer, Some(rows[1].0));
    let merged = manifest::read(&harness.db(), &harness.verify.config, &rows[1].4).await?;
    assert_eq!(merged.files[0].segments.len(), 2);
    assert_eq!(merged.upgrades_release_id, Some(release as i64));
    harness.finish().await
}

#[tokio::test]
async fn hot_collections_mark_pending_candidates_dirty_until_settled() -> Result<()> {
    let Some(harness) = Harness::start().await? else {
        return Ok(());
    };
    let first = harness.collection("ac", 1, &[("f1", 3, &[1])], 3).await?;
    candidates::archive(&harness.verify, first).await?;
    let late = harness
        .collection("ac", 1, &[("f1", 3, &[2, 3])], 0)
        .await?;

    let mut tx = harness.db().begin().await?;
    let unreferenced =
        candidates::invalidate_hot_collections(&harness.verify, &mut tx, &[late]).await?;
    tx.commit().await?;
    assert!(unreferenced.is_empty());
    let (status, version): (String, u32) =
        sqlx::query_as("SELECT status, version FROM release_verification_candidates")
            .fetch_one(&harness.db())
            .await?;
    assert_eq!((status.as_str(), version), ("dirty", 2));
    let filecheck: i8 = sqlx::query_scalar("SELECT filecheck FROM collections WHERE id = ?")
        .bind(late)
        .fetch_one(&harness.db())
        .await?;
    assert_eq!(filecheck, 3);

    // Settling archives the late headers and makes the candidate claimable again.
    let totals = prepare::settle_dirty(&harness.verify, 10, 5).await?;
    assert_eq!((totals.inspected, totals.settled, totals.kept), (1, 1, 0));
    let (status, version): (String, u32) =
        sqlx::query_as("SELECT status, version FROM release_verification_candidates")
            .fetch_one(&harness.db())
            .await?;
    assert_eq!((status.as_str(), version), ("ready", 3));
    assert_eq!(harness.count("collections").await?, 0);
    harness.finish().await
}

#[tokio::test]
async fn expiry_retires_stale_candidates_and_returns_their_capacity() -> Result<()> {
    let Some(harness) = Harness::start().await? else {
        return Ok(());
    };
    let id = harness.collection("ad", 1, &[("f1", 2, &[1])], 3).await?;
    candidates::archive(&harness.verify, id).await?;
    assert!(harness.pending_bytes().await? > 0);

    // Still owed its partial attempt: the expiry window has passed, but not the hold beyond it.
    sqlx::query(
        "UPDATE release_verification_candidates SET last_unique_at = UTC_TIMESTAMP() - INTERVAL 50 HOUR",
    )
    .execute(&harness.db())
    .await?;
    candidates::expire(&harness.verify, None).await?;
    let status: String = sqlx::query_scalar("SELECT status FROM release_verification_candidates")
        .fetch_one(&harness.db())
        .await?;
    assert_eq!(status, "waiting_upload");

    sqlx::query(
        "UPDATE release_verification_candidates SET last_unique_at = UTC_TIMESTAMP() - INTERVAL 53 HOUR",
    )
    .execute(&harness.db())
    .await?;
    harness
        .verify
        .cache
        .forget("release_verification:expiry_cursor")
        .await?;
    candidates::expire(&harness.verify, None).await?;
    let (status, path): (String, String) =
        sqlx::query_as("SELECT status, manifest_path FROM release_verification_candidates")
            .fetch_one(&harness.db())
            .await?;
    assert_eq!((status.as_str(), path.as_str()), ("expired", ""));
    assert_eq!(harness.pending_bytes().await?, 0);
    assert_eq!(harness.count("release_verification_manifests").await?, 0);
    harness.finish().await
}

#[tokio::test]
async fn stalled_uploads_are_promoted_once_the_hold_ends() -> Result<()> {
    let Some(harness) = Harness::start().await? else {
        return Ok(());
    };
    let id = harness.collection("ae", 1, &[("f1", 2, &[1])], 3).await?;
    candidates::archive(&harness.verify, id).await?;
    let promoted = candidates::promote_partial(&harness.verify, 10).await?;
    assert_eq!(promoted, 0);
    let (status, next): (String, chrono::NaiveDateTime) =
        sqlx::query_as("SELECT status, next_attempt_at FROM release_verification_candidates")
            .fetch_one(&harness.db())
            .await?;
    assert_eq!(status, "waiting_upload");
    // Deferred to the end of its hold window.
    assert!(next > candidates::now() + chrono::TimeDelta::hours(3));

    sqlx::query(
        "UPDATE release_verification_candidates SET last_unique_at = UTC_TIMESTAMP() - INTERVAL 5 HOUR, \
         next_attempt_at = UTC_TIMESTAMP() - INTERVAL 1 MINUTE",
    )
    .execute(&harness.db())
    .await?;
    assert_eq!(candidates::promote_partial(&harness.verify, 10).await?, 1);
    let (status, reason): (String, Option<String>) =
        sqlx::query_as("SELECT status, reason FROM release_verification_candidates")
            .fetch_one(&harness.db())
            .await?;
    assert_eq!(status, "ready");
    assert_eq!(
        reason.as_deref(),
        Some("Upload stopped growing before it was complete; queued for partial publication.")
    );
    harness.finish().await
}

#[tokio::test]
async fn claims_lease_one_candidate_at_a_time_and_finish_with_a_backoff() -> Result<()> {
    let Some(harness) = Harness::start().await? else {
        return Ok(());
    };
    let id = harness.collection("af", 1, &[("f1", 1, &[1])], 3).await?;
    candidates::archive(&harness.verify, id).await?;
    let mut locks = WorkerLocks::new(&harness.db(), &harness.verify.config).await?;
    assert!(locks.start(0).await?);
    let mut other = WorkerLocks::new(&harness.db(), &harness.verify.config).await?;
    assert!(other.start(0).await?);

    let claim = candidates::claim(&harness.verify, &mut locks, true)
        .await?
        .expect("claim");
    assert_eq!(claim.candidate.status, "ready");
    assert!(locks.protects(claim.id()).await);
    // Nothing else is due while the lease is live.
    assert!(
        candidates::claim(&harness.verify, &mut other, true)
            .await?
            .is_none()
    );

    let mut conn = harness.db().acquire().await?;
    candidates::heartbeat(&mut conn, 300, claim.id(), &claim.token).await?;
    assert!(
        candidates::heartbeat(&mut conn, 300, claim.id(), "wrong")
            .await
            .is_err()
    );
    drop(conn);

    candidates::finish(
        &harness.verify,
        &claim,
        &FinishedAttempt {
            reason: "article missing",
            deterministic: true,
            ..FinishedAttempt::default()
        },
    )
    .await?;
    let (status, token, next): (String, Option<String>, chrono::NaiveDateTime) = sqlx::query_as(
        "SELECT status, lease_token, next_attempt_at FROM release_verification_candidates",
    )
    .fetch_one(&harness.db())
    .await?;
    assert_eq!(status, "unknown");
    assert_eq!(token, None);
    assert!(next > candidates::now() + chrono::TimeDelta::hours(23));
    assert!(
        candidates::claim(&harness.verify, &mut other, true)
            .await?
            .is_none()
    );
    locks.close().await;
    other.close().await;
    harness.finish().await
}

#[tokio::test]
async fn a_retired_candidate_gives_back_its_capacity_and_fence() -> Result<()> {
    let Some(harness) = Harness::start().await? else {
        return Ok(());
    };
    let id = harness.collection("b1", 1, &[("f1", 1, &[1])], 3).await?;
    candidates::archive(&harness.verify, id).await?;
    let mut locks = WorkerLocks::new(&harness.db(), &harness.verify.config).await?;
    assert!(locks.start(0).await?);
    let claim = candidates::claim(&harness.verify, &mut locks, true)
        .await?
        .expect("claim");
    candidates::reject_by_policy(&harness.verify, &claim, "too small").await?;
    let (status, path, reason): (String, String, Option<String>) =
        sqlx::query_as("SELECT status, manifest_path, reason FROM release_verification_candidates")
            .fetch_one(&harness.db())
            .await?;
    assert_eq!((status.as_str(), path.as_str()), ("filtered", ""));
    assert_eq!(reason.as_deref(), Some("too small"));
    assert_eq!(harness.pending_bytes().await?, 0);
    // A stale outcome from the same lease is refused.
    assert!(
        candidates::reject_by_policy(&harness.verify, &claim, "again")
            .await
            .is_err()
    );
    locks.close().await;
    harness.finish().await
}

#[tokio::test]
async fn preparation_classifies_inventories_without_a_network() -> Result<()> {
    let Some(harness) = Harness::start().await? else {
        return Ok(());
    };
    let complete = harness.collection("c1", 1, &[("f1", 1, &[1])], 3).await?;
    let waiting = harness.collection("c2", 1, &[("f1", 2, &[1])], 3).await?;
    candidates::archive(&harness.verify, complete).await?;
    candidates::archive(&harness.verify, waiting).await?;
    sqlx::query("UPDATE release_verification_candidates SET status = 'pending'")
        .execute(&harness.db())
        .await?;
    let mut phase = 0u8;
    let changed = prepare::prepare(&harness.verify, 100, 5, |advance| {
        let next = (phase + 1) % 5;
        if advance {
            phase = next;
        }
        next != 0
    })
    .await?;
    assert_eq!(changed, 2);
    let statuses: Vec<String> =
        sqlx::query_scalar("SELECT status FROM release_verification_candidates ORDER BY id")
            .fetch_all(&harness.db())
            .await?;
    assert_eq!(statuses, ["ready", "waiting_upload"]);
    harness.finish().await
}

/// Workers spawn these, so their futures must stay `Send`.
#[test]
fn the_work_futures_are_send() {
    fn assert_send<T: Send>(_: T) {}
    fn check(
        verify: &nntmux_verify::context::Verify,
        cleanup: &nntmux_verify::collections::Cleanup,
    ) {
        assert_send(candidates::archive(verify, 1));
        assert_send(candidates::archive_each(verify, &[]));
        assert_send(candidates::expire(verify, None));
        assert_send(nntmux_verify::collections::delete_finished_and_orphans(
            verify, cleanup,
        ));
        assert_send(prepare::settle_dirty(verify, 1, 1));
    }
    let _ = check;
}

#[tokio::test]
async fn a_second_claim_attempt_keeps_the_lock_of_a_claim_in_flight() -> Result<()> {
    let Some(harness) = Harness::start().await? else {
        return Ok(());
    };
    let id = harness.collection("d0", 1, &[("f1", 1, &[1])], 3).await?;
    candidates::archive(&harness.verify, id).await?;
    let mut locks = WorkerLocks::new(&harness.db(), &harness.verify.config).await?;
    assert!(locks.start(0).await?);
    let claim = candidates::claim(&harness.verify, &mut locks, true)
        .await?
        .expect("claim");
    // The lease is live, so another claim finds nothing, and the stable lock stays.
    assert!(
        candidates::claim(&harness.verify, &mut locks, true)
            .await?
            .is_none()
    );
    assert!(locks.protects(claim.id()).await);
    locks.close().await;
    harness.finish().await
}

#[tokio::test]
async fn headers_that_conflict_with_a_partial_are_kept_apart_as_their_own_generation() -> Result<()>
{
    let Some(harness) = Harness::start().await? else {
        return Ok(());
    };
    let first = harness.collection("d1", 1, &[("f1", 2, &[1])], 3).await?;
    candidates::archive(&harness.verify, first).await?;
    let (candidate, path): (u64, String) =
        sqlx::query_as("SELECT id, manifest_path FROM release_verification_candidates")
            .fetch_one(&harness.db())
            .await?;
    let release = harness.published_partial(candidate, &path).await?;

    // Another post under the hash: the same part with another message ID.
    let other = harness
        .collection_with_ids("d1", 1, "other", &[("f1", 2, &[1])], 3)
        .await?;
    candidates::archive(&harness.verify, other).await?;
    let rows: Vec<CandidateSummary> = sqlx::query_as(
        "SELECT id, status, release_id, upgrades_release_id, manifest_path FROM release_verification_candidates ORDER BY id",
    )
    .fetch_all(&harness.db())
    .await?;
    assert_eq!(rows.len(), 2);
    assert_eq!(rows[0].4, path, "the partial keeps its inventory");
    assert_eq!(rows[1].2, None);
    assert_eq!(rows[1].3, None, "it doesn't upgrade the partial");
    let pointer: Option<u64> =
        sqlx::query_scalar("SELECT late_article_candidate_id FROM releases WHERE id = ?")
            .bind(release)
            .fetch_one(&harness.db())
            .await?;
    assert_eq!(pointer, Some(candidate));
    harness.finish().await
}

#[tokio::test]
async fn a_replay_of_a_partials_headers_only_records_new_groups() -> Result<()> {
    let Some(harness) = Harness::start().await? else {
        return Ok(());
    };
    let first = harness.collection("d2", 1, &[("f1", 2, &[1])], 3).await?;
    candidates::archive(&harness.verify, first).await?;
    let (candidate, path): (u64, String) =
        sqlx::query_as("SELECT id, manifest_path FROM release_verification_candidates")
            .fetch_one(&harness.db())
            .await?;
    let release = harness.published_partial(candidate, &path).await?;
    let replay = harness.collection("d2", 1, &[("f1", 2, &[1])], 3).await?;
    sqlx::query("INSERT INTO collection_groups (collections_id, group_name) VALUES (?, 'alt.binaries.test'), (?, 'a.b.crosspost')")
        .bind(replay)
        .bind(replay)
        .execute(&harness.db())
        .await?;
    candidates::archive(&harness.verify, replay).await?;
    assert_eq!(harness.count("release_verification_candidates").await?, 1);
    assert_eq!(harness.count("collections").await?, 0);
    let groups: Vec<String> = sqlx::query_scalar(
        "SELECT g.name FROM releases_groups AS rg INNER JOIN usenet_groups AS g ON g.id = rg.groups_id WHERE rg.releases_id = ?",
    )
    .bind(release)
    .fetch_all(&harness.db())
    .await?;
    assert_eq!(groups, ["alt.binaries.crosspost"]);
    harness.finish().await
}

#[tokio::test]
async fn a_generation_whose_partial_was_deleted_is_retired_not_merged() -> Result<()> {
    let Some(harness) = Harness::start().await? else {
        return Ok(());
    };
    let first = harness.collection("d3", 1, &[("f1", 2, &[1])], 3).await?;
    candidates::archive(&harness.verify, first).await?;
    sqlx::query("UPDATE release_verification_candidates SET upgrades_release_id = 999")
        .execute(&harness.db())
        .await?;
    let later = harness.collection("d3", 1, &[("f1", 2, &[2])], 3).await?;
    candidates::archive(&harness.verify, later).await?;
    let rows: Vec<CandidateSummary> = sqlx::query_as(
        "SELECT id, status, release_id, upgrades_release_id, manifest_path FROM release_verification_candidates ORDER BY id",
    )
    .fetch_all(&harness.db())
    .await?;
    assert_eq!(rows.len(), 2);
    assert_eq!((rows[0].1.as_str(), rows[0].4.as_str()), ("expired", ""));
    assert_eq!(rows[1].3, None);
    let fresh = manifest::read(&harness.db(), &harness.verify.config, &rows[1].4).await?;
    assert_eq!(
        fresh.files[0].segments.len(),
        1,
        "the retired articles don't come back"
    );
    let size = manifest::PreparedManifest::new(&fresh)?.compressed.len() as u64;
    assert_eq!(harness.pending_bytes().await?, size);
    harness.finish().await
}

#[tokio::test]
async fn headers_older_than_the_expiry_are_recorded_expired_without_inventory() -> Result<()> {
    let Some(harness) = Harness::start().await? else {
        return Ok(());
    };
    let id = harness.collection("d4", 1, &[("f1", 1, &[1])], 3).await?;
    sqlx::query(
        "UPDATE collections SET last_seen_at = UTC_TIMESTAMP() - INTERVAL 49 HOUR WHERE id = ?",
    )
    .bind(id)
    .execute(&harness.db())
    .await?;
    candidates::archive(&harness.verify, id).await?;
    let (status, path): (String, String) =
        sqlx::query_as("SELECT status, manifest_path FROM release_verification_candidates")
            .fetch_one(&harness.db())
            .await?;
    assert_eq!((status.as_str(), path.as_str()), ("expired", ""));
    assert_eq!(harness.count("collections").await?, 0);
    assert_eq!(harness.count("release_verification_manifests").await?, 0);
    assert_eq!(harness.pending_bytes().await?, 0);
    harness.finish().await
}

#[tokio::test]
async fn a_retained_inventory_is_released_after_the_window_and_its_fence_clears() -> Result<()> {
    let Some(harness) = Harness::start().await? else {
        return Ok(());
    };
    let first = harness.collection("d5", 1, &[("f1", 2, &[1])], 3).await?;
    candidates::archive(&harness.verify, first).await?;
    let (candidate, path): (u64, String) =
        sqlx::query_as("SELECT id, manifest_path FROM release_verification_candidates")
            .fetch_one(&harness.db())
            .await?;
    let release = harness.published_partial(candidate, &path).await?;
    sqlx::query(
        "UPDATE release_verification_candidates SET last_unique_at = UTC_TIMESTAMP() - INTERVAL 49 HOUR, \
         retained_last_unique_at = UTC_TIMESTAMP() - INTERVAL 49 HOUR",
    )
    .execute(&harness.db())
    .await?;
    candidates::expire(&harness.verify, None).await?;
    let (path, retained): (String, Option<u64>) = sqlx::query_as(
        "SELECT manifest_path, retained_for_release_id FROM release_verification_candidates",
    )
    .fetch_one(&harness.db())
    .await?;
    assert_eq!((path.as_str(), retained), ("", None));
    let pointer: Option<u64> =
        sqlx::query_scalar("SELECT late_article_candidate_id FROM releases WHERE id = ?")
            .bind(release)
            .fetch_one(&harness.db())
            .await?;
    assert_eq!(pointer, None);
    assert_eq!(harness.pending_bytes().await?, 0);
    harness.finish().await
}

#[tokio::test]
async fn a_late_article_pointer_at_a_finished_candidate_is_settled() -> Result<()> {
    let Some(harness) = Harness::start().await? else {
        return Ok(());
    };
    let release = sqlx::query(
        "INSERT INTO releases (name, searchname, groups_id, guid, leftguid, size, late_article_candidate_id) \
         VALUES ('n', 's', 1, 'e0e0e0e0-0000-0000-0000-000000000001', 'e', 1, 123456)",
    )
    .execute(&harness.db())
    .await?
    .last_insert_id();
    candidates::expire(&harness.verify, None).await?;
    let pointer: Option<u64> =
        sqlx::query_scalar("SELECT late_article_candidate_id FROM releases WHERE id = ?")
            .bind(release)
            .fetch_one(&harness.db())
            .await?;
    assert_eq!(pointer, None);
    harness.finish().await
}

fn cleanup() -> nntmux_verify::collections::Cleanup {
    nntmux_verify::collections::Cleanup {
        sql_chunk_size: 500,
        orphan_scan_window: 250_000,
        verification_sweep_seconds: 5,
        part_retention_hours: 72,
    }
}

#[tokio::test]
async fn retention_sweeps_archive_what_is_owed_and_delete_the_rest() -> Result<()> {
    let Some(harness) = Harness::start().await? else {
        return Ok(());
    };
    // Old and owed verification: archived, not lost.
    let owed = harness.collection("e1", 1, &[("f1", 1, &[1])], 3).await?;
    // Old, last seen past the expiry and unknown to any candidate: deleted outright.
    let expired = harness.collection("e2", 1, &[("f1", 1, &[1])], 3).await?;
    // An orphan with no binaries.
    let orphan = harness.collection("e3", 1, &[], 3).await?;
    // Fresh: left alone.
    let fresh = harness.collection("e4", 1, &[("f1", 2, &[1])], 0).await?;
    sqlx::query(
        "UPDATE collections SET dateadded = UTC_TIMESTAMP() - INTERVAL 80 HOUR WHERE id IN (?, ?)",
    )
    .bind(owed)
    .bind(expired)
    .execute(&harness.db())
    .await?;
    sqlx::query(
        "UPDATE collections SET last_seen_at = UTC_TIMESTAMP() - INTERVAL 60 HOUR WHERE id = ?",
    )
    .bind(expired)
    .execute(&harness.db())
    .await?;
    nntmux_verify::collections::delete_finished_and_orphans(&harness.verify, &cleanup()).await?;
    let left: Vec<u32> = sqlx::query_scalar("SELECT id FROM collections ORDER BY id")
        .fetch_all(&harness.db())
        .await?;
    assert_eq!(left, [fresh as u32], "orphan {orphan} went too");
    let sources: Vec<String> =
        sqlx::query_scalar("SELECT source_identity FROM release_verification_candidates")
            .fetch_all(&harness.db())
            .await?;
    assert_eq!(sources, [harness.source("e1")]);
    harness.finish().await
}

#[tokio::test]
async fn a_collection_with_a_usable_part_owed_verification_is_never_plain_deleted() -> Result<()> {
    let Some(harness) = Harness::start().await? else {
        return Ok(());
    };
    let owed = harness.collection("e5", 1, &[("f1", 1, &[1])], 3).await?;
    let done = harness.collection("e6", 1, &[], 3).await?;
    let deleted = nntmux_verify::collections::delete_collections_and_descendants(
        &harness.db(),
        &cleanup(),
        &[owed, done],
        "test",
    )
    .await?;
    assert_eq!(deleted, 1);
    assert_eq!(harness.count("parts").await?, 1);
    harness.finish().await
}

#[tokio::test]
async fn a_replay_leaves_an_upload_still_owed_its_partial_attempt() -> Result<()> {
    let Some(harness) = Harness::start().await? else {
        return Ok(());
    };
    let first = harness.collection("e7", 1, &[("f1", 2, &[1])], 3).await?;
    candidates::archive(&harness.verify, first).await?;
    sqlx::query(
        "UPDATE release_verification_candidates SET last_unique_at = UTC_TIMESTAMP() - INTERVAL 50 HOUR",
    )
    .execute(&harness.db())
    .await?;
    let replay = harness.collection("e7", 1, &[("f1", 2, &[1])], 3).await?;
    sqlx::query(
        "UPDATE collections SET last_seen_at = UTC_TIMESTAMP() - INTERVAL 50 HOUR WHERE id = ?",
    )
    .bind(replay)
    .execute(&harness.db())
    .await?;
    candidates::archive(&harness.verify, replay).await?;
    let (status, path): (String, String) =
        sqlx::query_as("SELECT status, manifest_path FROM release_verification_candidates")
            .fetch_one(&harness.db())
            .await?;
    assert_eq!(status, "waiting_upload");
    assert_ne!(path, "");
    assert_eq!(harness.count("collections").await?, 0);
    harness.finish().await
}
