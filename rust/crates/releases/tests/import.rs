//! An upload from the API's staging, through import and verification, to a release.

use std::path::{Path, PathBuf};

use anyhow::Result;
use nntmux_api::upload::{self, UploadedFile};
use nntmux_categorize::Categorizer;
use nntmux_predb::PredbIndex;
use nntmux_releases::Releases;
use nntmux_releases::import::{self, Options};
use nntmux_releases::upload_manifest::{self as uploads, FILENAME};
use nntmux_testkit::TestDb;
use nntmux_verify::cache::Cache;
use nntmux_verify::candidates;
use nntmux_verify::cleaning::ReleaseCleaner;
use nntmux_verify::config::VerificationConfig;
use nntmux_verify::context::Verify;
use nntmux_verify::locks::WorkerLocks;
use nntmux_verify::manifest::{self, Evidence};
use nntmux_verify::metadata::Resolver;
use nntmux_verify::publisher::{Outcome, publish};
use nntmux_verify::spot_check::{VerificationResult, with_evidence};
use rand::Rng;
use serde_json::Value;

struct Harness {
    db: TestDb,
    releases: Releases,
    folder: PathBuf,
}

const NZB: &str = r#"<?xml version="1.0" encoding="UTF-8"?>
<nzb xmlns="http://www.newzbin.com/DTD/2003/nzb">
  <file poster="poster@example.com" date="1700000000" subject="[1/1] - &quot;Some.Release.2024.1080p-GRP.mkv&quot; yEnc (1/1)">
    <groups><group>alt.binaries.test</group></groups>
    <segments><segment bytes="500000000" number="1">a@example.com</segment></segments>
  </file>
</nzb>
"#;

impl Harness {
    async fn start() -> Result<Option<Self>> {
        let (Some(db), Ok(redis_url)) = (TestDb::create().await?, std::env::var("TEST_REDIS_URL"))
        else {
            return Ok(None);
        };
        let redis = redis::Client::open(redis_url)?
            .get_connection_manager()
            .await?;
        let prefix = format!("t_{:016x}", rand::rng().random::<u64>());
        let root = std::env::temp_dir().join(&prefix);
        let folder = root.join("uploads");
        std::fs::create_dir_all(&folder)?;
        let config = VerificationConfig::for_tests(root);
        sqlx::query(
            "INSERT INTO release_verification_state (id, pending_bytes, audit_cursor) VALUES (1, 0, 0) \
             ON DUPLICATE KEY UPDATE pending_bytes = 0",
        )
        .execute(&db.pool)
        .await?;
        sqlx::query("DELETE FROM usenet_groups WHERE id <> 1 AND name = 'alt.binaries.test'")
            .execute(&db.pool)
            .await?;
        sqlx::query(
            "INSERT INTO usenet_groups (id, name, active, backfill) VALUES (1, 'alt.binaries.test', 1, 0) \
             ON DUPLICATE KEY UPDATE name = VALUES(name), minfilestoformrelease = NULL, minsizetoformrelease = NULL",
        )
        .execute(&db.pool)
        .await?;
        sqlx::query("UPDATE ingestion_configurations SET min_files_to_form_release = 1")
            .execute(&db.pool)
            .await?;
        sqlx::query("DELETE FROM binaryblacklist")
            .execute(&db.pool)
            .await?;
        let releases = Releases {
            verify: Verify::new(
                db.pool.clone(),
                Cache::new(redis, format!("{prefix}:")),
                config,
            ),
        };
        Ok(Some(Self {
            db,
            releases,
            folder,
        }))
    }

    fn options(&self, delete: bool) -> Options {
        Options {
            folder: self.folder.clone(),
            use_filename: false,
            delete,
            delete_failed: false,
        }
    }

    fn stage(&self, nfo: Option<&[u8]>) -> Result<PathBuf> {
        let nzb = UploadedFile {
            field: "nzb".to_owned(),
            filename: "Some.Release.nzb".to_owned(),
            content: NZB.as_bytes().to_vec(),
        };
        let nfo = nfo.map(|content| UploadedFile {
            field: "nfo".to_owned(),
            filename: "Some.Release.nfo".to_owned(),
            content: content.to_vec(),
        });
        upload::stage(Some(&self.folder), &nzb, nfo.as_ref())
            .map_err(|failure| anyhow::anyhow!("{failure:?}"))?;
        let directory = std::fs::read_dir(&self.folder)?
            .flatten()
            .map(|entry| entry.path())
            .find(|path| {
                path.is_dir()
                    && !path
                        .file_name()
                        .is_some_and(|name| name.to_string_lossy().starts_with('.'))
            })
            .expect("a staged upload");
        Ok(directory)
    }

    /// Claims the queued candidate and publishes it as the verify role would.
    async fn verify_and_publish(&self) -> Result<Outcome> {
        let verify = &self.releases.verify;
        let mut locks = WorkerLocks::new(&verify.db, &verify.config).await?;
        assert!(locks.start(1).await?);
        let claim = candidates::claim(verify, &mut locks, true)
            .await?
            .expect("a due candidate");
        let stored =
            manifest::read(&verify.db, &verify.config, &claim.candidate.manifest_path).await?;
        let evidence = Evidence {
            articles: 1,
            bytes: 100,
            sampled_files: 1,
            verified_file_names: vec!["Some.Release.2024.1080p-GRP.mkv".to_owned()],
            ..Evidence::default()
        };
        let result = VerificationResult {
            verdict: "sampled".to_owned(),
            reason: "ok".to_owned(),
            manifest: Some(with_evidence(&stored, &evidence)),
            ..VerificationResult::default()
        };
        let cleaner = ReleaseCleaner::new();
        let predb = PredbIndex::disabled();
        let categorizer = Categorizer::load(&verify.db).await?;
        let resolver = Resolver {
            db: &verify.db,
            cleaner: &cleaner,
            predb: &predb,
            categorizer: &categorizer,
        };
        let outcome = publish(verify, &resolver, &claim, &result).await?;
        locks.close().await;
        Ok(outcome)
    }

    async fn scalar(&self, sql: &str) -> Result<i64> {
        Ok(sqlx::query_scalar(sqlx::AssertSqlSafe(sql.to_owned()))
            .fetch_one(&self.db.pool)
            .await?)
    }
}

fn manifest_of(directory: &Path) -> Result<Value> {
    uploads::read(&directory.join(FILENAME))
}

#[tokio::test]
async fn a_staged_upload_becomes_a_release_with_its_nfo() -> Result<()> {
    let Some(harness) = Harness::start().await? else {
        return Ok(());
    };
    let directory = harness.stage(Some(b"the nfo text"))?;
    let nzb = directory.join("Some.Release.nzb");
    let nfo = directory.join("Some.Release.nfo");

    // Import only admits it: nothing is published, and the source waits for the release.
    let report = import::import_folder(&harness.releases, &harness.options(true)).await?;
    assert_eq!(report.imported, 1);
    assert_eq!(harness.scalar("SELECT COUNT(*) FROM releases").await?, 0);
    let manifest = manifest_of(&directory)?;
    assert_eq!(manifest["state"], "nzb_pending");
    assert!(manifest["candidate_identity"].is_string());
    assert!(nzb.exists(), "kept until its NFO is stored");

    // Scanning again changes nothing while verification is pending.
    let again = import::import_folder(&harness.releases, &harness.options(true)).await?;
    assert_eq!(again.imported, 0);
    assert_eq!(
        harness
            .scalar("SELECT COUNT(*) FROM release_verification_candidates")
            .await?,
        1
    );

    let Outcome::Published(release) = harness.verify_and_publish().await? else {
        panic!("published");
    };

    // The next scan records the release.
    import::import_folder(&harness.releases, &harness.options(true)).await?;
    let manifest = manifest_of(&directory)?;
    assert_eq!(manifest["state"], "nzb_imported");
    assert_eq!(manifest["release_id"].as_i64(), Some(release));

    // Its NFO is stored with a receipt, and both inputs go.
    let (imported, skipped, failed) =
        import::import_companions(&harness.releases, &harness.folder, true).await?;
    assert_eq!((imported, skipped, failed), (1, 0, 0));
    assert_eq!(manifest_of(&directory)?["state"], "nfo_imported");
    assert!(!nzb.exists() && !nfo.exists());
    let (stored, status): (Vec<u8>, i64) = sqlx::query_as(
        "SELECT n.nfo, CAST(r.nfostatus AS SIGNED) FROM release_nfos n JOIN releases r ON r.id = n.releases_id WHERE n.releases_id = ?",
    )
    .bind(release)
    .fetch_one(&harness.db.pool)
    .await?;
    assert_eq!(&stored[..4], &[0x1f, 0x8b, 0x08, 0x00]);
    assert_eq!(status, 1);
    assert_eq!(
        harness
            .scalar("SELECT COUNT(*) FROM nfo_cleanup_commits")
            .await?,
        1
    );

    // A restart does nothing further.
    let (imported, skipped, failed) =
        import::import_companions(&harness.releases, &harness.folder, true).await?;
    assert_eq!((imported, failed), (0, 0));
    assert_eq!(skipped, 1);
    Ok(())
}

#[tokio::test]
async fn without_an_nfo_the_nzb_goes_once_its_release_exists() -> Result<()> {
    let Some(harness) = Harness::start().await? else {
        return Ok(());
    };
    let directory = harness.stage(None)?;
    let nzb = directory.join("Some.Release.nzb");
    import::import_folder(&harness.releases, &harness.options(true)).await?;
    assert!(nzb.exists());
    harness.verify_and_publish().await?;
    import::import_folder(&harness.releases, &harness.options(true)).await?;
    assert_eq!(manifest_of(&directory)?["state"], "nzb_imported");
    assert!(!nzb.exists());
    Ok(())
}

#[tokio::test]
async fn a_file_replaced_at_the_same_path_is_never_deleted() -> Result<()> {
    let Some(harness) = Harness::start().await? else {
        return Ok(());
    };
    let directory = harness.stage(None)?;
    let nzb = directory.join("Some.Release.nzb");
    import::import_folder(&harness.releases, &harness.options(true)).await?;
    harness.verify_and_publish().await?;
    std::fs::write(&nzb, b"<nzb>another upload</nzb>")?;
    import::import_folder(&harness.releases, &harness.options(true)).await?;
    assert_eq!(std::fs::read(&nzb)?, b"<nzb>another upload</nzb>");
    assert_eq!(manifest_of(&directory)?["state"], "metadata_review");
    Ok(())
}

#[tokio::test]
async fn sources_that_may_succeed_later_stay() -> Result<()> {
    let Some(harness) = Harness::start().await? else {
        return Ok(());
    };
    // A dropped file without a manifest is admitted, but no capture authorizes deleting it.
    let dropped = harness.folder.join("dropped.nzb");
    std::fs::write(&dropped, NZB)?;
    let report = import::import_folder(&harness.releases, &harness.options(true)).await?;
    assert_eq!(report.imported, 1);
    assert!(dropped.exists());

    // Password metadata in the second of two heads is held for review.
    let password = harness.folder.join("password.nzb");
    std::fs::write(
        &password,
        NZB.replace(
            "<file ",
            "<head/><head><meta type=\"password\">x</meta></head><file ",
        ),
    )?;
    let report = import::import_folder(&harness.releases, &harness.options(true)).await?;
    assert!(report.skipped >= 1);
    assert!(password.exists());
    Ok(())
}

#[tokio::test]
async fn blacklisted_and_groupless_uploads_end_here() -> Result<()> {
    let Some(harness) = Harness::start().await? else {
        return Ok(());
    };
    sqlx::query(
        "INSERT INTO binaryblacklist (groupname, regex, msgcol, optype, status) VALUES ('alt.binaries.test', 'Some.Release', 1, 1, 1)",
    )
    .execute(&harness.db.pool)
    .await?;
    let blacklisted = harness.folder.join("blacklisted.nzb");
    std::fs::write(&blacklisted, NZB)?;
    let groupless = harness.folder.join("groupless.nzb");
    std::fs::write(&groupless, NZB.replace("alt.binaries.test", "not a group!"))?;
    let keep = harness.folder.join("notes.txt");
    std::fs::write(&keep, b"x")?;
    let report = import::import_folder(&harness.releases, &harness.options(true)).await?;
    assert_eq!(report.imported, 0);
    assert!(!blacklisted.exists() && !groupless.exists());
    assert!(keep.exists());
    assert_eq!(
        harness
            .scalar("SELECT COUNT(*) FROM release_verification_candidates")
            .await?,
        0
    );
    Ok(())
}

#[test]
fn names_come_from_the_path_and_subject() {
    assert_eq!(
        import::name_from_path(Path::new("x/Show - S01E07 [1080p].mkv.nzb.gz")),
        "Show - S01E07 [1080p]"
    );
    assert_eq!(import::normalize_subject("a b (1/2)"), "a b yEnc");
    assert_eq!(import::normalize_subject("a yEnc (1/2) junk"), "a yEnc");
}
