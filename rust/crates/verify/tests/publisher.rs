//! Publishing verified candidates against a real database.

#[allow(dead_code)]
mod support;

use anyhow::Result;
use nntmux_categorize::Categorizer;
use nntmux_predb::PredbIndex;
use nntmux_verify::candidates::{self, Claim};
use nntmux_verify::cleaning::ReleaseCleaner;
use nntmux_verify::locks::WorkerLocks;
use nntmux_verify::manifest::{self, Evidence, Manifest};
use nntmux_verify::metadata::Resolver;
use nntmux_verify::nzb;
use nntmux_verify::publisher::{Outcome, PublishError, publish};
use nntmux_verify::spot_check::{VerificationResult, with_evidence};
use support::Harness;

struct Publisher {
    cleaner: ReleaseCleaner,
    predb: PredbIndex,
    categorizer: Categorizer,
}

impl Publisher {
    async fn new(harness: &Harness) -> Result<Self> {
        Ok(Self {
            cleaner: ReleaseCleaner::new(),
            predb: PredbIndex::disabled(),
            categorizer: Categorizer::load(&harness.db()).await?,
        })
    }

    async fn publish(
        &self,
        harness: &Harness,
        claim: &Claim,
        result: &VerificationResult,
    ) -> Result<Outcome> {
        let db = harness.db();
        let resolver = Resolver {
            db: &db,
            cleaner: &self.cleaner,
            predb: &self.predb,
            categorizer: &self.categorizer,
        };
        publish(&harness.verify, &resolver, claim, result).await
    }
}

fn passed(manifest: &Manifest, partial: bool) -> VerificationResult {
    let evidence = Evidence {
        articles: 1,
        bytes: 100,
        sampled_files: 1,
        verified_file_names: vec!["movie.mkv".to_owned()],
        partial,
        ..Evidence::default()
    };
    VerificationResult {
        verdict: "sampled".to_owned(),
        reason: "ok".to_owned(),
        manifest: Some(with_evidence(manifest, &evidence)),
        waiting_for_headers: false,
        header_rejected: false,
        deterministic: false,
    }
}

/// Archives a collection, claims its candidate and reads the manifest it holds.
async fn claimed(harness: &Harness, hash: &str) -> Result<(Claim, Manifest)> {
    let collection = harness
        .collection(hash, 2, &[("a", 2, &[1, 2]), ("b", 1, &[1])], 1)
        .await?;
    candidates::archive(&harness.verify, collection).await?;
    claimed_next(harness).await
}

async fn claimed_next(harness: &Harness) -> Result<(Claim, Manifest)> {
    let mut locks = WorkerLocks::new(&harness.db(), &harness.verify.config).await?;
    assert!(locks.start(1).await?);
    let claim = candidates::claim(&harness.verify, &mut locks, true)
        .await?
        .expect("a due candidate");
    let manifest = manifest::read(
        &harness.db(),
        &harness.verify.config,
        &claim.candidate.manifest_path,
    )
    .await?;
    locks.close().await;
    Ok((claim, manifest))
}

async fn scalar(harness: &Harness, sql: &str) -> Result<i64> {
    Ok(sqlx::query_scalar(sqlx::AssertSqlSafe(sql.to_owned()))
        .fetch_one(&harness.db())
        .await?)
}

#[tokio::test]
async fn a_new_release_commits_with_its_nzb_search_and_fingerprint_work() -> Result<()> {
    let Some(harness) = Harness::start().await? else {
        return Ok(());
    };
    let publisher = Publisher::new(&harness).await?;
    let (claim, manifest) = claimed(&harness, "aa").await?;
    let before = harness.pending_bytes().await?;
    assert!(before > 0);

    let outcome = publisher
        .publish(&harness, &claim, &passed(&manifest, false))
        .await?;
    let Outcome::Published(id) = outcome else {
        panic!("published, not {outcome:?}");
    };

    let (guid, total, size, status): (String, i64, i64, i64) = sqlx::query_as(
        "SELECT guid, CAST(totalpart AS SIGNED), CAST(size AS SIGNED), CAST(nzbstatus AS SIGNED) FROM releases WHERE id = ?",
    )
    .bind(id)
    .fetch_one(&harness.db())
    .await?;
    assert_eq!((total, status), (2, 1));
    assert_eq!(size, 300);
    assert_eq!(guid.len(), 36);
    assert!(nzb::exists(&harness.db(), &guid).await?);
    assert_eq!(harness.count("release_fingerprint_jobs").await?, 1);
    assert_eq!(harness.count("search_index_failures").await?, 1);
    assert_eq!(harness.count("release_regexes").await?, 1);
    let (status, path, verified): (String, String, Option<chrono::NaiveDateTime>) = sqlx::query_as(
        "SELECT status, manifest_path, verified_at FROM release_verification_candidates WHERE id = ?",
    )
    .bind(claim.id())
    .fetch_one(&harness.db())
    .await?;
    assert_eq!((status.as_str(), path.as_str()), ("sampled", ""));
    assert!(verified.is_some());
    assert_eq!(harness.pending_bytes().await?, 0, "capacity is released");
    assert_eq!(harness.count("release_verification_manifests").await?, 0);

    // A second publish of the same claim is stale, not a second release.
    let again = publisher
        .publish(&harness, &claim, &passed(&manifest, false))
        .await;
    let failure = again.expect_err("stale");
    assert!(matches!(
        failure.downcast_ref::<PublishError>(),
        Some(PublishError::Stale)
    ));
    assert_eq!(harness.count("releases").await?, 1);
    harness.finish().await
}

#[tokio::test]
async fn a_release_holding_the_same_articles_is_reused() -> Result<()> {
    let Some(harness) = Harness::start().await? else {
        return Ok(());
    };
    let publisher = Publisher::new(&harness).await?;
    let (first, manifest) = claimed(&harness, "ab").await?;
    let Outcome::Published(id) = publisher
        .publish(&harness, &first, &passed(&manifest, false))
        .await?
    else {
        panic!("published");
    };

    // Another candidate over the same message IDs under a different identity.
    let collection = harness
        .collection_with_ids("ac", 2, "msg-ab", &[("a", 2, &[1, 2]), ("b", 1, &[1])], 1)
        .await?;
    candidates::archive(&harness.verify, collection).await?;
    let (second, mut other) = claimed_next(&harness).await?;
    // The stored document decides, so give it the first candidate's articles and subjects.
    other.files = manifest.files.clone();
    let outcome = publisher
        .publish(&harness, &second, &passed(&other, false))
        .await?;
    assert_eq!(outcome, Outcome::Duplicate(id));
    assert_eq!(harness.count("releases").await?, 1);

    harness.finish().await
}

#[tokio::test]
async fn a_duplicate_that_loses_a_password_goes_to_review() -> Result<()> {
    let Some(harness) = Harness::start().await? else {
        return Ok(());
    };
    let publisher = Publisher::new(&harness).await?;
    let (first, manifest) = claimed(&harness, "ad").await?;
    publisher
        .publish(&harness, &first, &passed(&manifest, false))
        .await?;
    let collection = harness
        .collection_with_ids("ae", 2, "msg-ad", &[("a", 2, &[1, 2]), ("b", 1, &[1])], 1)
        .await?;
    candidates::archive(&harness.verify, collection).await?;
    let (second, mut other) = claimed_next(&harness).await?;
    other.files = manifest.files.clone();
    other.passwords = Some(vec!["secret".to_owned()]);
    let outcome = publisher
        .publish(&harness, &second, &passed(&other, false))
        .await?;
    assert_eq!(outcome, Outcome::MetadataReview);
    assert_eq!(harness.count("releases").await?, 1);
    let status: String =
        sqlx::query_scalar("SELECT status FROM release_verification_candidates WHERE id = ?")
            .bind(second.id())
            .fetch_one(&harness.db())
            .await?;
    assert_eq!(status, "metadata_review");
    harness.finish().await
}

#[tokio::test]
async fn policy_filters_and_defers() -> Result<()> {
    let Some(harness) = Harness::start().await? else {
        return Ok(());
    };
    let publisher = Publisher::new(&harness).await?;
    sqlx::query("UPDATE ingestion_configurations SET min_size_to_form_release = 1000000")
        .execute(&harness.db())
        .await?;
    let (claim, manifest) = claimed(&harness, "af").await?;
    let outcome = publisher
        .publish(&harness, &claim, &passed(&manifest, false))
        .await?;
    assert_eq!(outcome, Outcome::Filtered);
    assert_eq!(harness.count("releases").await?, 0);
    assert_eq!(harness.pending_bytes().await?, 0);
    let status: String =
        sqlx::query_scalar("SELECT status FROM release_verification_candidates WHERE id = ?")
            .bind(claim.id())
            .fetch_one(&harness.db())
            .await?;
    assert_eq!(status, "filtered");
    harness.finish().await
}

#[tokio::test]
async fn a_partial_keeps_its_inventory_and_upgrades_in_place() -> Result<()> {
    let Some(harness) = Harness::start_with(|config| config.partial_after_hours = 1).await? else {
        return Ok(());
    };
    let publisher = Publisher::new(&harness).await?;
    let collection = harness
        .collection("b0", 2, &[("a", 2, &[1]), ("b", 1, &[1])], 1)
        .await?;
    candidates::archive(&harness.verify, collection).await?;
    // Old enough to publish as partial.
    sqlx::query(
        "UPDATE release_verification_candidates SET last_unique_at = UTC_TIMESTAMP() - INTERVAL 2 HOUR",
    )
    .execute(&harness.db())
    .await?;
    assert_eq!(candidates::promote_partial(&harness.verify, 10).await?, 1);
    let (claim, manifest) = claimed_next(&harness).await?;
    let Outcome::Published(id) = publisher
        .publish(&harness, &claim, &passed(&manifest, true))
        .await?
    else {
        panic!("published");
    };
    let (found, expected, fence): (Option<i64>, Option<i64>, Option<i64>) = sqlx::query_as(
        "SELECT CAST(articles_found AS SIGNED), CAST(articles_expected AS SIGNED), \
         CAST(late_article_candidate_id AS SIGNED) FROM releases WHERE id = ?",
    )
    .bind(id)
    .fetch_one(&harness.db())
    .await?;
    assert_eq!((found, expected), (Some(2), Some(3)));
    assert_eq!(fence, Some(claim.id()));
    let retained = scalar(
        &harness,
        "SELECT COUNT(*) FROM release_verification_candidates WHERE manifest_path <> '' AND retained_for_release_id IS NOT NULL",
    )
    .await?;
    assert_eq!(retained, 1, "late articles can still complete it");

    // A later generation completes it.
    let late = harness.collection("b0", 2, &[("a", 2, &[2])], 1).await?;
    candidates::archive(&harness.verify, late).await?;
    let (upgrade_claim, upgrade_manifest) = claimed_next(&harness).await?;
    assert_eq!(upgrade_manifest.upgrades_release_id, Some(id));
    sqlx::query("UPDATE releases SET haspreview = 1, jpgstatus = 1 WHERE id = ?")
        .bind(id)
        .execute(&harness.db())
        .await?;
    let outcome = publisher
        .publish(&harness, &upgrade_claim, &passed(&upgrade_manifest, false))
        .await?;
    assert_eq!(outcome, Outcome::Upgraded(id));
    assert_eq!(harness.count("releases").await?, 1);
    let (found, fence, preview, jpg): (Option<i64>, Option<i64>, i64, i64) = sqlx::query_as(
        "SELECT CAST(articles_found AS SIGNED), CAST(late_article_candidate_id AS SIGNED), \
         CAST(haspreview AS SIGNED), CAST(jpgstatus AS SIGNED) FROM releases WHERE id = ?",
    )
    .bind(id)
    .fetch_one(&harness.db())
    .await?;
    assert_eq!(found, None);
    assert_eq!(fence, None, "complete now, so workers may read it");
    assert_eq!((preview, jpg), (-1, 0), "post-processing is redone");
    let metadata: String =
        sqlx::query_scalar("SELECT status FROM release_fingerprint_metadata WHERE releases_id = ?")
            .bind(id)
            .fetch_one(&harness.db())
            .await?;
    assert_eq!(metadata, "stale");
    harness.finish().await
}

#[tokio::test]
async fn an_upgrade_of_a_deleted_partial_is_abandoned() -> Result<()> {
    let Some(harness) = Harness::start().await? else {
        return Ok(());
    };
    let publisher = Publisher::new(&harness).await?;
    let (claim, mut manifest) = claimed(&harness, "c0").await?;
    manifest.upgrades_release_id = Some(999_999);
    let outcome = publisher
        .publish(&harness, &claim, &passed(&manifest, false))
        .await?;
    assert_eq!(outcome, Outcome::Abandoned);
    assert_eq!(harness.count("releases").await?, 0);
    harness.finish().await
}
