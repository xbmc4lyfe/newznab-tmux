//! Queueing listed files and finalizing a release against a real database.

use std::sync::{Arc, Mutex};

use anyhow::Result;
use async_trait::async_trait;
use chrono::Utc;
use nntmux_categorize::Categorizer;
use nntmux_namefix::queries::FixNamesConfig;
use nntmux_namefix::service::NameFixer;
use nntmux_namefix::update::{ReleaseSearch, ReleaseUpdater};
use nntmux_postprocess::additional::candidates::ClaimedRelease;
use nntmux_postprocess::additional::context::ReleaseContext;
use nntmux_postprocess::additional::release_files::{ReleaseFiles, Settings};
use nntmux_postprocess::additional::search_sync::SearchSync;
use nntmux_postprocess::archive_info::{FileRecord, ListEntry};
use nntmux_predb::filename::fixer_cleaner;
use nntmux_predb::identity::{self, InsertOutcome, NewPredb};
use nntmux_testkit::TestDb;
use nntmux_verify::in_tx;
use nntmux_verify::nzb::{self, ReleaseRef};
use sqlx::MySqlPool;

const DOCUMENT: &[u8] = b"<nzb><file subject=\"&quot;Movie.part01.rar&quot; yEnc (1/1)\">\
    <segments><segment number=\"1\" bytes=\"60\">m1@x</segment></segments></file></nzb>";
const TOKEN: &str = "0123456789abcdef0123456789abcdef";
const SCENE: &str = "Some.Movie.2021.2160p.BluRay.x265-GROUP";

#[derive(Default)]
struct Recorder(Mutex<Vec<i64>>);

#[async_trait]
impl ReleaseSearch for Recorder {
    async fn update_release(&self, release_id: i64) {
        self.0.lock().expect("unpoisoned").push(release_id);
    }
}

/// A claimed release with a stored NZB, and its source digest.
async fn release(pool: &MySqlPool, guid: &str) -> Result<(ClaimedRelease, String)> {
    let id = sqlx::query(
        "INSERT INTO releases (name, searchname, totalpart, groups_id, fromname, size, postdate, \
         adddate, guid, leftguid, categories_id, nzbstatus, additional_pp_claim_token, \
         additional_pp_claimed_at) VALUES ('n', 's', 1, 1, 'f', 5000000, '2026-01-01 00:00:00', \
         '2026-01-01 00:00:00', ?, ?, 10, 1, ?, ?)",
    )
    .bind(guid)
    .bind(&guid[..1])
    .bind(TOKEN)
    .bind(Utc::now().naive_utc())
    .execute(pool)
    .await?
    .last_insert_id() as i64;
    let reference = ReleaseRef {
        id,
        guid: guid.to_owned(),
    };
    let body = nzb::compress(DOCUMENT)?;
    in_tx!(pool, 1, |tx| {
        nzb::put_locked(tx, &reference, &body, None, false).await
    })?;
    let claimed = ClaimedRelease {
        id,
        guid: guid.to_owned(),
        name: "n".to_owned(),
        searchname: "s".to_owned(),
        size: 5_000_000,
        groups_id: 1,
        nfostatus: 0,
        fromname: Some("f".to_owned()),
        completion: 100.0,
        categories_id: 10,
        predb_id: 0,
        pp_timeout_count: 0,
        claim_token: Some(TOKEN.to_owned()),
    };
    Ok((claimed, nzb::digest(DOCUMENT)))
}

fn context(claimed: ClaimedRelease, digest: &str) -> ReleaseContext {
    let mut context = ReleaseContext::new(claimed);
    context.nzb_source_digest = Some(digest.to_owned());
    context.archive_naming_inspection_complete = true;
    context
}

async fn manager(
    pool: &MySqlPool,
    covers: &std::path::Path,
) -> Result<(ReleaseFiles, Arc<Recorder>)> {
    let recorder = Arc::new(Recorder::default());
    let categorizer = Categorizer::load(pool).await?;
    let updater = ReleaseUpdater::new(pool.clone(), categorizer, recorder.clone(), fixer_cleaner);
    let fixer = NameFixer::new(updater, FixNamesConfig::from_env());
    let settings = Settings {
        inner_file_blacklist: Some("/sample/i".to_owned()),
        add_par2_files: true,
        season_pack_max_release_files: 50,
        archive_retry_delay_seconds: 600,
        claim_ttl_seconds: 3600,
        covers_path: covers.to_owned(),
        image_extension: "jpg".to_owned(),
    };
    let sync = SearchSync::new(recorder.clone(), true);
    Ok((
        ReleaseFiles::new(pool.clone(), settings, fixer, sync)?,
        recorder,
    ))
}

fn file(name: &str, size: i64, pass: i64) -> ListEntry {
    ListEntry::File(FileRecord {
        name: name.as_bytes().to_vec(),
        size,
        date: 1_700_000_000,
        pass,
        crc32: Some("ABCD1234".to_owned()),
        ..FileRecord::default()
    })
}

async fn files(pool: &MySqlPool, id: i64) -> Result<Vec<(String, i64, String)>> {
    Ok(sqlx::query_as(
        "SELECT name, CAST(size AS SIGNED), crc32 FROM release_files WHERE releases_id = ? ORDER BY name",
    )
    .bind(id)
    .fetch_all(pool)
    .await?)
}

/// (passwordstatus, rarinnerfilecount, haspreview, claim token, archive_retry_at set)
async fn statuses(pool: &MySqlPool, id: i64) -> Result<(i64, i64, i64, Option<String>, bool)> {
    Ok(sqlx::query_as(
        "SELECT CAST(passwordstatus AS SIGNED), CAST(rarinnerfilecount AS SIGNED), \
         CAST(haspreview AS SIGNED), additional_pp_claim_token, archive_retry_at IS NOT NULL \
         FROM releases WHERE id = ?",
    )
    .bind(id)
    .fetch_one(pool)
    .await?)
}

async fn evidence_scope(pool: &MySqlPool, id: i64) -> Result<String> {
    Ok(sqlx::query_scalar(
        "SELECT scope FROM release_archive_naming_evidence WHERE releases_id = ?",
    )
    .bind(id)
    .fetch_one(pool)
    .await?)
}

async fn reclaim(pool: &MySqlPool, id: i64) -> Result<()> {
    sqlx::query("UPDATE releases SET additional_pp_claim_token = ?, additional_pp_claimed_at = ? WHERE id = ?")
        .bind(TOKEN)
        .bind(Utc::now().naive_utc())
        .bind(id)
        .execute(pool)
        .await?;
    Ok(())
}

#[tokio::test]
async fn listed_files_are_queued_and_finalized_with_their_statuses() -> Result<()> {
    let Some(db) = TestDb::create().await? else {
        return Ok(());
    };
    let pool = &db.pool;
    let covers = tempfile::tempdir()?;
    let (claimed, digest) = release(pool, "a1").await?;
    let id = claimed.id;
    sqlx::query("INSERT INTO release_files (releases_id, name, size) VALUES (?, 'old.mkv', 1)")
        .bind(id)
        .execute(pool)
        .await?;
    let (mut manager, recorder) = manager(pool, covers.path()).await?;
    let mut context = context(claimed, &digest);
    manager.sync.begin_release_scope(id);

    // A one-file post is still a post: the movie is queued on its own.
    assert!(
        manager
            .add_file_info(&file("Feature.2160p.mkv", 60_000_000_000, 0), &mut context)
            .await?
    );
    // Support files, duplicates, bad sizes and stored names aren't.
    assert!(
        !manager
            .add_file_info(&file("Feature.par2", 10, 0), &mut context)
            .await?
    );
    assert!(
        !manager
            .add_file_info(&file("Feature.part02.rar", 10, 0), &mut context)
            .await?
    );
    assert!(
        !manager
            .add_file_info(&file("Feature.2160p.mkv", 60_000_000_000, 0), &mut context)
            .await?
    );
    assert!(
        !manager
            .add_file_info(&file("broken.mkv", -1, 0), &mut context)
            .await?
    );
    assert!(
        !manager
            .add_file_info(&file("old.mkv", 1, 0), &mut context)
            .await?
    );
    assert!(
        !manager
            .add_file_info(
                &ListEntry::Error {
                    error: "e".to_owned(),
                    source: "s".to_owned()
                },
                &mut context
            )
            .await?
    );
    assert_eq!(context.pending_release_files.keys(), ["Feature.2160p.mkv"]);
    assert!(!context.release_has_password);
    // An encrypted entry marks the release passworded, unqueued.
    assert!(
        !manager
            .add_file_info(&file("Feature.nfo", 10, 1), &mut context)
            .await?
    );
    assert!(context.release_has_password);
    manager
        .queue_release_file(
            &mut context,
            "x.par2",
            5,
            0,
            0,
            "ABCDEF0123456789ABCDEF0123456789",
            "",
        )
        .await?;

    manager.finalize_release(&mut context, true).await?;
    manager.sync.finish_release_scope().await;
    assert!(!context.full_par2_evidence_deferred);
    assert!(context.release_files_changed);
    assert!(context.pending_release_files.is_empty());
    assert_eq!(
        files(pool, id).await?,
        [
            (
                "Feature.2160p.mkv".to_owned(),
                60_000_000_000,
                "ABCD1234".to_owned()
            ),
            ("old.mkv".to_owned(), 1, String::new()),
            ("x.par2".to_owned(), 5, String::new()),
        ]
    );
    assert_eq!(statuses(pool, id).await?, (1, 3, 0, None, false));
    let hashes: Vec<String> =
        sqlx::query_scalar("SELECT hash FROM par_hashes WHERE releases_id = ?")
            .bind(id)
            .fetch_all(pool)
            .await?;
    assert_eq!(hashes, ["abcdef0123456789abcdef0123456789"]);
    let created: String = sqlx::query_scalar(
        "SELECT DATE_FORMAT(created_at, '%Y-%m-%d %H:%i:%s') FROM release_files WHERE name = 'Feature.2160p.mkv'",
    )
    .fetch_one(pool)
    .await?;
    assert_eq!(created, "2023-11-14 22:13:20");
    assert_eq!(evidence_scope(pool, id).await?, "complete_single");
    assert_eq!(*recorder.0.lock().expect("unpoisoned"), [id]);
    db.drop_database().await
}

#[tokio::test]
async fn an_unreadable_listing_is_retried_once() -> Result<()> {
    let Some(db) = TestDb::create().await? else {
        return Ok(());
    };
    let pool = &db.pool;
    let covers = tempfile::tempdir()?;
    let (claimed, digest) = release(pool, "a1").await?;
    let id = claimed.id;
    let (mut manager, _) = manager(pool, covers.path()).await?;

    let mut first = context(claimed.clone(), &digest);
    first.nzb_has_compressed_file = true;
    first.archive_inspection_attempted = true;
    manager.finalize_release(&mut first, true).await?;
    assert_eq!(statuses(pool, id).await?, (-1, 0, -1, None, true));
    assert_eq!(evidence_scope(pool, id).await?, "incomplete");

    // The retry found nothing either: it stays unknown rather than retrying forever.
    reclaim(pool, id).await?;
    let mut second = context(claimed, &digest);
    second.nzb_has_compressed_file = true;
    second.archive_inspection_attempted = true;
    manager.finalize_release(&mut second, true).await?;
    assert_eq!(statuses(pool, id).await?, (-1, 0, 0, None, true));
    db.drop_database().await
}

#[tokio::test]
async fn a_lost_claim_or_changed_nzb_writes_nothing() -> Result<()> {
    let Some(db) = TestDb::create().await? else {
        return Ok(());
    };
    let pool = &db.pool;
    let covers = tempfile::tempdir()?;
    let (claimed, digest) = release(pool, "a1").await?;
    let id = claimed.id;
    let (mut manager, recorder) = manager(pool, covers.path()).await?;

    // Another worker renamed the release.
    let mut renamed = context(claimed.clone(), &digest);
    manager
        .add_file_info(&file("Feature.mkv", 100, 0), &mut renamed)
        .await?;
    sqlx::query("UPDATE releases SET searchname = 'other' WHERE id = ?")
        .bind(id)
        .execute(pool)
        .await?;
    manager.finalize_release(&mut renamed, true).await?;
    assert!(renamed.full_par2_evidence_deferred);
    assert!(files(pool, id).await?.is_empty());
    assert_eq!(statuses(pool, id).await?.3.as_deref(), Some(TOKEN));

    // The NZB changed under the attempt.
    sqlx::query("UPDATE releases SET searchname = 's' WHERE id = ?")
        .bind(id)
        .execute(pool)
        .await?;
    let mut stale = context(claimed.clone(), &"0".repeat(64));
    manager
        .add_file_info(&file("Feature.mkv", 100, 0), &mut stale)
        .await?;
    manager.finalize_release(&mut stale, true).await?;
    assert!(stale.full_par2_evidence_deferred);
    assert!(files(pool, id).await?.is_empty());

    // An expired claim no longer owns the release.
    sqlx::query(
        "UPDATE releases SET additional_pp_claimed_at = NOW() - INTERVAL 2 DAY WHERE id = ?",
    )
    .bind(id)
    .execute(pool)
    .await?;
    let mut expired = context(claimed.clone(), &digest);
    manager
        .add_file_info(&file("Feature.mkv", 100, 0), &mut expired)
        .await?;
    manager.finalize_release(&mut expired, true).await?;
    assert!(expired.full_par2_evidence_deferred);
    assert!(files(pool, id).await?.is_empty());

    // Without a digest the evidence can't be tied to a source.
    reclaim(pool, id).await?;
    let mut sourceless = context(claimed, &digest);
    sourceless.nzb_source_digest = None;
    manager.finalize_release(&mut sourceless, true).await?;
    assert!(sourceless.full_par2_evidence_deferred);
    assert!(recorder.0.lock().expect("unpoisoned").is_empty());
    db.drop_database().await
}

#[tokio::test]
async fn timeouts_skip_then_delete() -> Result<()> {
    let Some(db) = TestDb::create().await? else {
        return Ok(());
    };
    let pool = &db.pool;
    let covers = tempfile::tempdir()?;
    let (claimed, _) = release(pool, "a1").await?;
    let id = claimed.id;
    let (mut manager, recorder) = manager(pool, covers.path()).await?;

    let context = ReleaseContext::new(claimed.clone());
    assert!(!manager.handle_release_timeout(&context, 3).await?);
    let (count, token): (i64, Option<String>) = sqlx::query_as(
        "SELECT CAST(pp_timeout_count AS SIGNED), additional_pp_claim_token FROM releases WHERE id = ?",
    )
    .bind(id)
    .fetch_one(pool)
    .await?;
    assert_eq!((count, token), (1, None));
    assert_eq!(*recorder.0.lock().expect("unpoisoned"), [id]);

    let mut last = claimed;
    last.pp_timeout_count = 2;
    assert!(
        manager
            .handle_release_timeout(&ReleaseContext::new(last), 3)
            .await?
    );
    let left: i64 = sqlx::query_scalar("SELECT COUNT(*) FROM releases WHERE id = ?")
        .bind(id)
        .fetch_one(pool)
        .await?;
    assert_eq!(left, 0);
    db.drop_database().await
}

async fn certify(pool: &MySqlPool) -> Result<()> {
    let (generation, _, _) = identity::rebuild_start(pool).await?;
    loop {
        let (_, _, done) = identity::rebuild_chunk(pool, &generation, 128).await?;
        if done {
            break;
        }
    }
    identity::rebuild_certify(pool, &generation).await?;
    Ok(())
}

#[tokio::test]
async fn a_listed_scene_filename_names_the_release_and_keeps_ownership() -> Result<()> {
    let Some(db) = TestDb::create().await? else {
        return Ok(());
    };
    let pool = &db.pool;
    let predb = NewPredb {
        title: SCENE.to_owned(),
        source: "t".to_owned(),
        ..NewPredb::default()
    };
    let InsertOutcome::Inserted(predb_id) = identity::insert_feed(pool, &predb).await? else {
        panic!("predb row rejected");
    };
    certify(pool).await?;
    let covers = tempfile::tempdir()?;
    let (claimed, digest) = release(pool, "a1").await?;
    let id = claimed.id;
    let (mut manager, _) = manager(pool, covers.path()).await?;
    let mut context = context(claimed, &digest);

    assert!(
        manager
            .add_file_info(&file(&format!("{SCENE}.mkv"), 100, 0), &mut context)
            .await?
    );
    let (searchname, linked): (String, i64) =
        sqlx::query_as("SELECT searchname, CAST(predb_id AS SIGNED) FROM releases WHERE id = ?")
            .bind(id)
            .fetch_one(pool)
            .await?;
    assert_eq!((searchname.as_str(), linked), (SCENE, predb_id as i64));
    // The attempt followed its own rename, so it still owns the release and finalizes.
    assert_eq!(context.release.searchname, SCENE);
    assert!(!context.full_par2_evidence_deferred);
    manager.finalize_release(&mut context, true).await?;
    assert!(!context.full_par2_evidence_deferred);
    assert_eq!(files(pool, id).await?.len(), 1);
    assert_eq!(statuses(pool, id).await?.3, None);

    // Names held back for a possible pack are tried in order, skipping support files.
    let (claimed, digest) = release(pool, "b1").await?;
    let other = claimed.id;
    let mut held = self::context(claimed, &digest);
    let listed = [file("x.sfv", 1, 0), file(&format!("{SCENE}.mkv"), 100, 0)];
    manager
        .match_file_names_to_predb(&listed, &mut held)
        .await?;
    let searchname: String = sqlx::query_scalar("SELECT searchname FROM releases WHERE id = ?")
        .bind(other)
        .fetch_one(pool)
        .await?;
    assert_eq!(searchname, SCENE);
    assert_eq!(held.release.searchname, SCENE);
    db.drop_database().await
}
