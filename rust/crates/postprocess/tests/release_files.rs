//! Queueing listed files, PAR2 and NFO evidence, naming from inner names, and finalizing a
//! release against a real database.

#[allow(dead_code)]
mod support;

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
use nntmux_postprocess::additional::planner::NzbFile;
use nntmux_postprocess::additional::release_files::{ReleaseFiles, Settings};
use nntmux_postprocess::additional::search_sync::SearchSync;
use nntmux_postprocess::archive_info::{FileRecord, ListEntry, Summary};
use nntmux_postprocess::nfo::{self, NfoService};
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
        rename_par2: true,
        echo_cli: true,
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

#[tokio::test]
async fn an_episode_name_held_for_the_inventory_names_the_release_after_finalizing() -> Result<()> {
    let Some(db) = TestDb::create().await? else {
        return Ok(());
    };
    let pool = &db.pool;
    let episode = "Show.Name.S01E01.1080p.WEB-DL-GROUP";
    let predb = NewPredb {
        title: episode.to_owned(),
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
    // While the release might be a pack, a listed episode isn't tried for naming yet.
    context.season_pack_probable = true;
    assert!(
        manager
            .add_file_info(&file(&format!("{episode}.mkv"), 100, 0), &mut context)
            .await?
    );
    let before: String = sqlx::query_scalar("SELECT searchname FROM releases WHERE id = ?")
        .bind(id)
        .fetch_one(pool)
        .await?;
    assert_eq!(before, "s");

    // The complete inventory shows a single episode, so finalizing names the release.
    manager.finalize_release(&mut context, true).await?;
    assert_eq!(evidence_scope(pool, id).await?, "complete_single");
    let (searchname, linked): (String, i64) =
        sqlx::query_as("SELECT searchname, CAST(predb_id AS SIGNED) FROM releases WHERE id = ?")
            .bind(id)
            .fetch_one(pool)
            .await?;
    assert_eq!((searchname.as_str(), linked), (episode, predb_id as i64));
    db.drop_database().await
}

/// One PAR2 packet with a valid checksum.
fn par2_packet(kind: &[u8; 16], body: &[u8]) -> Vec<u8> {
    use md5::{Digest, Md5};
    let mut tail = Vec::new();
    tail.extend_from_slice(&[7u8; 16]);
    tail.extend_from_slice(kind);
    tail.extend_from_slice(body);
    let mut packet = b"PAR2\0PKT".to_vec();
    packet.extend_from_slice(&(64 + body.len() as u64).to_le_bytes());
    packet.extend_from_slice(&Md5::digest(&tail));
    packet.extend_from_slice(&tail);
    packet
}

fn par2_file_desc(id: u8, name: &str, length: u64) -> Vec<u8> {
    let mut body = vec![id; 16];
    body.extend_from_slice(&[0xAB; 16]);
    body.extend_from_slice(&[0xCD; 16]);
    body.extend_from_slice(&length.to_le_bytes());
    body.extend_from_slice(name.as_bytes());
    while !body.len().is_multiple_of(4) {
        body.push(0);
    }
    par2_packet(b"PAR 2.0\0FileDesc", &body)
}

#[tokio::test]
async fn a_par2_file_queues_its_files_and_hashes_and_names_an_other_release() -> Result<()> {
    let Some(db) = TestDb::create().await? else {
        return Ok(());
    };
    let pool = &db.pool;
    let covers = tempfile::tempdir()?;
    let scratch = tempfile::tempdir()?;
    let (claimed, digest) = release(pool, "a1").await?;
    let id = claimed.id;
    let (mut manager, _) = manager(pool, covers.path()).await?;
    let mut context = context(claimed, &digest);

    // One big file, as a single remux would be, plus its sample.
    let mut data = par2_file_desc(1, &format!("{SCENE}.mkv"), 60_000_000_000);
    data.extend(par2_file_desc(2, "sample.mkv", 1_000));
    let path = scratch.path().join("x.par2");
    std::fs::write(&path, &data)?;
    assert!(manager.process_par2_file(&path, &mut context).await?);
    assert!(context.found_par2_info);
    let names: Vec<&String> = context.pending_release_files.keys().iter().collect();
    assert_eq!(names, [&format!("{SCENE}.mkv"), &"sample.mkv".to_owned()]);
    let queued = context
        .pending_release_files
        .values()
        .next()
        .expect("a queued file");
    assert_eq!(queued.size, 60_000_000_000);
    assert_eq!(queued.created_at, "2026-01-01 00:00:00");
    assert_eq!(context.pending_par_hashes.keys(), ["cd".repeat(16)]);
    let full: Vec<_> = context.pending_full_par_hashes.values().cloned().collect();
    assert_eq!(full.len(), 2);
    assert_eq!(full[0].full_md5, "ab".repeat(16));
    assert_eq!(full[0].size.as_deref(), Some("60000000000"));
    // PreDB is matched on whole titles, and the extension spoils that, so the file name
    // extractor names it without a link, as PHP's check did.
    let (searchname, linked): (String, i64) =
        sqlx::query_as("SELECT searchname, CAST(predb_id AS SIGNED) FROM releases WHERE id = ?")
            .bind(id)
            .fetch_one(pool)
            .await?;
    assert_eq!((searchname.as_str(), linked), (SCENE, 0));
    assert!(!context.full_par2_evidence_deferred);

    // A packet that fails its checksum, or a missing file, adds nothing.
    let mut broken = data.clone();
    let last = broken.len() - 1;
    broken[last] ^= 1;
    std::fs::write(&path, &broken)?;
    let (claimed, digest) = release(pool, "b1").await?;
    let mut other = self::context(claimed, &digest);
    assert!(!manager.process_par2_file(&path, &mut other).await?);
    assert!(
        !manager
            .process_par2_file(&scratch.path().join("gone.par2"), &mut other)
            .await?
    );
    assert!(other.pending_release_files.is_empty() && !other.found_par2_info);
    db.drop_database().await
}

const NFO_TEXT: &[u8] = b"\xb0\xb1\xb2 GROUP \xb2\xb1\xb0\r\n  Release Date ....: 2021-05-01\r\n  \
    Source ..........: UHD BluRay\r\n  Video ...........: HEVC 10bit HDR\r\n  \
    Audio ...........: English TrueHD Atmos 7.1\r\n  \
    IMDB ............: https://www.imdb.com/title/tt0000001/\r\n";

#[tokio::test]
async fn a_downloaded_nfo_is_converted_from_cp437_and_stored() -> Result<()> {
    let Some(db) = TestDb::create().await? else {
        return Ok(());
    };
    let pool = &db.pool;
    let covers = tempfile::tempdir()?;
    let scratch = tempfile::tempdir()?;
    let (claimed, digest) = release(pool, "a1").await?;
    let id = claimed.id;
    let (manager, _) = manager(pool, covers.path()).await?;
    let mut context = context(claimed, &digest);
    context.release_has_no_nfo = true;
    let mut service = NfoService::new(
        pool.clone(),
        nfo::Settings {
            batch: 1,
            lowest_status: nfo::lowest_status(5),
            max_size: 0,
            min_size: 0,
            lookup_par2: false,
            alternate: false,
            unrar: None,
            tmp_path: scratch.path().to_owned(),
            command_timeout: std::time::Duration::from_secs(5),
        },
        support::client(1),
    );

    let junk = scratch.path().join("junk.nfo");
    std::fs::write(&junk, [0x01u8; 200])?;
    assert!(
        !manager
            .process_nfo_file(&junk, &mut context, &mut service)
            .await?
    );
    assert!(
        !manager
            .process_nfo_file(&scratch.path().join("gone.nfo"), &mut context, &mut service)
            .await?
    );
    assert!(context.release_has_no_nfo);

    let path = scratch.path().join("x.nfo");
    std::fs::write(&path, NFO_TEXT)?;
    assert!(
        manager
            .process_nfo_file(&path, &mut context, &mut service)
            .await?
    );
    assert!(!context.release_has_no_nfo);
    let (status, stored): (i64, Vec<u8>) = sqlx::query_as(
        "SELECT CAST(r.nfostatus AS SIGNED), UNCOMPRESS(n.nfo) FROM releases r \
         JOIN release_nfos n ON n.releases_id = r.id WHERE r.id = ?",
    )
    .bind(id)
    .fetch_one(pool)
    .await?;
    assert_eq!(status, nfo::NFO_FOUND);
    let stored = String::from_utf8(stored)?;
    assert!(stored.starts_with("░▒▓ GROUP ▓▒░\r\n"), "{stored}");
    db.drop_database().await
}

fn summary(names: &[&str], archives: Vec<(&str, Summary)>) -> Summary {
    Summary {
        file_list: names.iter().map(|name| file(name, 100, 0)).collect(),
        archives: archives
            .into_iter()
            .map(|(name, inner)| (name.as_bytes().to_vec(), inner))
            .collect(),
        ..Summary::default()
    }
}

async fn searchname(pool: &MySqlPool, id: i64) -> Result<(String, i64)> {
    Ok(
        sqlx::query_as("SELECT searchname, CAST(predb_id AS SIGNED) FROM releases WHERE id = ?")
            .bind(id)
            .fetch_one(pool)
            .await?,
    )
}

#[tokio::test]
async fn the_first_inner_file_names_the_release() -> Result<()> {
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
    let (mut manager, _) = manager(pool, covers.path()).await?;

    // A PreDB title, matched case-insensitively, names the release with its link.
    let (claimed, digest) = release(pool, "a1").await?;
    let id = claimed.id;
    let mut context = context(claimed, &digest);
    let lower = format!("{}.mkv", SCENE.to_lowercase());
    manager
        .process_release_name_from_rar(&summary(&[&lower, "x.nfo"], vec![]), &mut context)
        .await?;
    assert_eq!(
        searchname(pool, id).await?,
        (SCENE.to_owned(), predb_id as i64)
    );
    assert_eq!(context.release.searchname, SCENE);

    // An unreadable outer name falls through to the archive stored under it.
    let (claimed, digest) = release(pool, "b1").await?;
    let nested_id = claimed.id;
    let mut nested = self::context(claimed, &digest);
    let inner = summary(&[&format!("{SCENE}.mkv")], vec![]);
    manager
        .process_release_name_from_rar(
            &summary(&["abc123.rar"], vec![("abc123.rar", inner)]),
            &mut nested,
        )
        .await?;
    assert_eq!(searchname(pool, nested_id).await?.0, SCENE);

    // A possible season pack, or an implausible name, leaves the release alone.
    let (claimed, digest) = release(pool, "c1").await?;
    let held_id = claimed.id;
    let mut held = self::context(claimed, &digest);
    held.season_pack_probable = true;
    manager
        .process_release_name_from_rar(&summary(&[&format!("{SCENE}.mkv")], vec![]), &mut held)
        .await?;
    held.season_pack_probable = false;
    manager
        .process_release_name_from_rar(&summary(&["Movie.2021.mkv.mkv"], vec![]), &mut held)
        .await?;
    assert_eq!(searchname(pool, held_id).await?, ("s".to_owned(), 0));
    db.drop_database().await
}

#[tokio::test]
async fn an_nzbsplit_wrapped_release_is_named_from_its_nzb_titles() -> Result<()> {
    let Some(db) = TestDb::create().await? else {
        return Ok(());
    };
    let pool = &db.pool;
    let covers = tempfile::tempdir()?;
    let (mut manager, _) = manager(pool, covers.path()).await?;
    let wrapped = |title: &str| NzbFile {
        title: title.to_owned(),
        ..NzbFile::default()
    };
    let files = [
        wrapped(""),
        wrapped("TEST__NZBSPLIT__1234567890abcdef__NZBSPLIT__setup.7z.001"),
        wrapped(
            "MOVIE__NZBSPLIT__bdab31d6f79989608009e7e8eadcbe66__NZBSPLIT__Some_Movie_2021_2160p_BluRay_x265-GROUP.7z.001",
        ),
    ];

    let (claimed, digest) = release(pool, "a1").await?;
    let id = claimed.id;
    let mut plain = context(claimed, &digest);
    assert!(
        !manager
            .process_release_name_from_nzb_contents(&files, &mut plain)
            .await?
    );
    assert_eq!(searchname(pool, id).await?.0, "s");

    let (mut claimed, digest) = release(pool, "b1").await?;
    let wrapped_id = claimed.id;
    let name = "MOVIE__NZBSPLIT__bdab31d6f79989608009e7e8eadcbe66__NZBSPLIT__x";
    sqlx::query("UPDATE releases SET name = ? WHERE id = ?")
        .bind(name)
        .bind(wrapped_id)
        .execute(pool)
        .await?;
    claimed.name = name.to_owned();
    let mut context = self::context(claimed, &digest);
    assert!(
        manager
            .process_release_name_from_nzb_contents(&files, &mut context)
            .await?
    );
    assert_eq!(
        searchname(pool, wrapped_id).await?.0,
        "Some.Movie.2021.2160p.BluRay.x265-GROUP"
    );
    db.drop_database().await
}
