//! Whole releases through the processor: a stored NZB in a real database, articles from a
//! scripted NNTP server, and recorded media steps.

#[allow(dead_code)]
mod support;

use std::path::Path;
use std::sync::{Arc, Mutex};

use anyhow::Result;
use async_trait::async_trait;
use chrono::Utc;
use nntmux_categorize::Categorizer;
use nntmux_namefix::queries::FixNamesConfig;
use nntmux_namefix::service::NameFixer;
use nntmux_namefix::update::{ReleaseSearch, ReleaseUpdater};
use nntmux_postprocess::additional::archive_extraction::Extractor;
use nntmux_postprocess::additional::candidates::ClaimedRelease;
use nntmux_postprocess::additional::context::ReleaseContext;
use nntmux_postprocess::additional::media_source::ResolverConfig;
use nntmux_postprocess::additional::media_stage::{MediaSteps, StageSettings};
use nntmux_postprocess::additional::processor::{
    Outcome, ProcessorConfig, ProcessorSettings, ReleaseProcessor, ReleaseResult, Stage,
};
use nntmux_postprocess::additional::release_files::{ReleaseFiles, Settings};
use nntmux_postprocess::additional::search_sync::SearchSync;
use nntmux_postprocess::config::PlannerConfig;
use nntmux_postprocess::media::container::MediaSourceHints;
use nntmux_postprocess::media::slicer::SlicerSettings;
use nntmux_postprocess::nfo::{self, NfoService};
use nntmux_predb::filename::fixer_cleaner;
use nntmux_testkit::TestDb;
use nntmux_verify::in_tx;
use nntmux_verify::nzb::{self, ReleaseRef};
use sqlx::MySqlPool;
use support::{post, serve, service};

const TOKEN: &str = "0123456789abcdef0123456789abcdef";
const FEATURE: &str = "Movie.2023.1080p.BluRay.x264-GRP.mkv";

#[derive(Default)]
struct Synced(Mutex<Vec<i64>>);

#[async_trait]
impl ReleaseSearch for Synced {
    async fn update_release(&self, release_id: i64) {
        self.0.lock().unwrap().push(release_id);
    }
}

/// Records which steps saw a file; samples always succeed, nothing else does.
#[derive(Default)]
struct Media(Mutex<Vec<(&'static str, usize)>>);

impl Media {
    fn record(&self, step: &'static str, file: &Path) {
        let len = std::fs::metadata(file).map_or(0, |meta| meta.len() as usize);
        self.0.lock().unwrap().push((step, len));
    }

    fn steps(&self) -> Vec<&'static str> {
        self.0
            .lock()
            .unwrap()
            .iter()
            .map(|(step, _)| *step)
            .collect()
    }
}

#[async_trait]
impl MediaSteps for Media {
    async fn sample(&self, file: &Path, _: &Path, _: &str, _: Option<&MediaSourceHints>) -> bool {
        self.record("sample", file);
        true
    }

    async fn media_info(&self, file: &Path, _: i64) -> bool {
        self.record("media_info", file);
        false
    }

    async fn video(&self, file: &Path, _: &Path, _: &str, _: Option<&MediaSourceHints>) -> bool {
        self.record("video", file);
        false
    }

    async fn usable_image(&self, file: &Path) -> bool {
        self.record("usable_image", file);
        false
    }

    async fn jpg_sample(&self, file: &Path, _: &str) -> bool {
        self.record("jpg_sample", file);
        false
    }
}

fn config() -> ProcessorConfig {
    ProcessorConfig {
        settings: ProcessorSettings {
            process_passwords: true,
            process_thumbnails: true,
            process_media_info: true,
            process_video: false,
            process_jpg_sample: true,
            fetch_last_files: false,
            max_nested_levels: 3,
            maximum_rar_segments: 5,
            maximum_rar_password_checks: 1,
            season_pack_archive_sets: 4,
            release_timeout_seconds: 120,
            max_pp_timeout_count: 3,
            media_max_bytes: 16_777_216,
            media_image_max_bytes: 2_097_152,
        },
        planner: PlannerConfig {
            process_thumbnails: true,
            process_jpg_sample: true,
            process_media_info: true,
            process_audio_info: false,
            segments_to_download: 2,
            maximum_rar_segments: 5,
            archive_probe_files: 2,
            par2_media_mapping: false,
            par2_index_max_segments: 2,
            media_main_video_fallback: true,
            media_main_video_min_bytes: 100_000,
            media_image_max_bytes: 2_097_152,
        },
        resolver: ResolverConfig {
            par2_media_mapping: false,
            par2_verify_files: 0,
        },
        slicer: SlicerSettings {
            archive_slicing: true,
            process_thumbnails: true,
            sample_max_bytes: 8_388_608,
            main_video_min_bytes: 100_000,
        },
        stage: StageSettings {
            process_thumbnails: true,
            stage_max_seconds: 30,
            main_video_fallback: true,
            mid_sources: false,
            max_bytes: 16_777_216,
            sample_max_bytes: 8_388_608,
            mid_bytes: 262_144,
            release_timeout_seconds: 120,
            ffmpeg_timeout: 30,
        },
        extractor: Extractor::default(),
    }
}

/// A claimed release whose NZB lists the given posts.
async fn release(
    pool: &MySqlPool,
    guid: &str,
    files: &[nntmux_postprocess::additional::planner::NzbFile],
) -> Result<ClaimedRelease> {
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
    if !files.is_empty() {
        let mut document = String::from("<nzb>");
        for file in files {
            document.push_str(&format!(
                "<file subject=\"{}\"><segments>",
                file.title.replace('"', "&quot;")
            ));
            for (number, id) in file.segments.iter().enumerate() {
                document.push_str(&format!(
                    "<segment number=\"{}\" bytes=\"20000\">{id}</segment>",
                    number + 1
                ));
            }
            document.push_str("</segments></file>");
        }
        document.push_str("</nzb>");
        let reference = ReleaseRef {
            id,
            guid: guid.to_owned(),
        };
        let body = nzb::compress(document.as_bytes())?;
        in_tx!(pool, 1, |tx| {
            nzb::put_locked(tx, &reference, &body, None, false).await
        })?;
    }
    Ok(ClaimedRelease {
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
    })
}

async fn manager(pool: &MySqlPool, covers: &Path) -> Result<ReleaseFiles> {
    let synced = Arc::new(Synced::default());
    let categorizer = Categorizer::load(pool).await?;
    let updater = ReleaseUpdater::new(pool.clone(), categorizer, synced.clone(), fixer_cleaner);
    let fixer = NameFixer::new(updater, FixNamesConfig::from_env());
    let settings = Settings {
        inner_file_blacklist: None,
        add_par2_files: true,
        rename_par2: false,
        echo_cli: true,
        season_pack_max_release_files: 50,
        archive_retry_delay_seconds: 600,
        claim_ttl_seconds: 3600,
        covers_path: covers.to_owned(),
        image_extension: "jpg".to_owned(),
    };
    ReleaseFiles::new(pool.clone(), settings, fixer, SearchSync::new(synced, true))
}

fn nfo_service(pool: &MySqlPool, scratch: &Path) -> NfoService {
    NfoService::new(
        pool.clone(),
        nfo::Settings {
            batch: 1,
            lowest_status: nfo::lowest_status(5),
            max_size: 0,
            min_size: 0,
            lookup_par2: false,
            alternate: false,
            unrar: None,
            tmp_path: scratch.to_owned(),
            command_timeout: std::time::Duration::from_secs(5),
        },
        support::client(1),
    )
}

/// Runs one release through a fresh processor against `articles`.
async fn process(
    pool: &MySqlPool,
    claimed: ClaimedRelease,
    articles: Vec<(&'static str, Vec<u8>)>,
    main_tmp: &Path,
    media: &Media,
) -> Result<(ReleaseResult, ReleaseContext)> {
    let covers = tempfile::tempdir()?;
    let mut files = manager(pool, covers.path()).await?;
    let mut nfo = nfo_service(pool, main_tmp);
    let server = serve(articles).await;
    let mut downloads = service(server.port);
    let mut processor = ReleaseProcessor::new(
        config(),
        pool.clone(),
        &mut files,
        &mut nfo,
        &mut downloads,
        media,
    );
    let mut context = ReleaseContext::new(claimed);
    let result = processor.process(&mut context, main_tmp).await?;
    Ok((result, context))
}

async fn release_files(pool: &MySqlPool, id: i64) -> Result<Vec<String>> {
    Ok(
        sqlx::query_scalar("SELECT name FROM release_files WHERE releases_id = ? ORDER BY name")
            .bind(id)
            .fetch_all(pool)
            .await?,
    )
}

async fn exists(pool: &MySqlPool, id: i64) -> Result<bool> {
    let count: i64 = sqlx::query_scalar("SELECT COUNT(*) FROM releases WHERE id = ?")
        .bind(id)
        .fetch_one(pool)
        .await?;
    Ok(count == 1)
}

fn volume(number: u8) -> Vec<u8> {
    std::fs::read(Path::new(env!("CARGO_MANIFEST_DIR")).join(format!(
        "tests/fixtures/media/stored/movie.part{number}.rar"
    )))
    .unwrap()
}

#[tokio::test]
async fn a_stored_archive_set_is_listed_previewed_and_finalized() -> Result<()> {
    let Some(db) = TestDb::create().await? else {
        return Ok(());
    };
    let pool = &db.pool;
    let tmp = tempfile::tempdir()?;
    let mut nzb = Vec::new();
    let mut articles = Vec::new();
    for (number, prefix) in [(1, "v1"), (2, "v2"), (3, "v3")] {
        let (file, posted) = post(prefix, &format!("movie.part{number}.rar"), &volume(number));
        nzb.push(file);
        articles.extend(posted);
    }
    let claimed = release(pool, "b1", &nzb).await?;
    let id = claimed.id;
    let media = Media::default();

    let (result, context) = process(pool, claimed, articles, tmp.path(), &media).await?;

    assert_eq!(result.outcome, Outcome::Completed, "{result:?}");
    assert!(result.artifacts_created);
    assert_eq!(result.release_files_added, 4);
    assert!(context.found_sample);
    assert!(media.steps().contains(&"sample"), "{:?}", media.steps());
    assert!(
        result
            .stage_durations
            .iter()
            .any(|(stage, _)| *stage == Stage::ArchiveDownloads)
    );
    assert!(result.download_metrics.logical_requests > 0);
    assert_eq!(
        release_files(pool, id).await?,
        vec![
            "Extras/packed.mkv".to_owned(),
            FEATURE.to_owned(),
            "Movie.2023.1080p.BluRay.x264-GRP.nfo".to_owned(),
            "Sample/movie-sample.mkv".to_owned(),
        ]
    );
    // The release's temp folder is gone; the worker's stays.
    assert!(!tmp.path().join("b1").exists());
    assert!(tmp.path().exists());
    // The downloaded archive kept for the NFO fallback was let go.
    assert!(context.downloaded_archives.is_empty());
    db.drop_database().await
}

#[tokio::test]
async fn a_lone_par2_file_deletes_the_release() -> Result<()> {
    let Some(db) = TestDb::create().await? else {
        return Ok(());
    };
    let pool = &db.pool;
    let tmp = tempfile::tempdir()?;
    let mut par2 = b"PAR2\0PKT".to_vec();
    par2.extend_from_slice(&[0u8; 200]);
    let (file, articles) = post("p", "a8f3c29d10e4", &par2);
    let claimed = release(pool, "c1", &[file]).await?;
    let id = claimed.id;

    let (result, _) = process(pool, claimed, articles, tmp.path(), &Media::default()).await?;

    assert_eq!(result.outcome, Outcome::DeletedPar2Only, "{result:?}");
    assert!(result.outcome.is_deleted());
    assert!(!exists(pool, id).await?);
    db.drop_database().await
}

#[tokio::test]
async fn a_release_replaced_meanwhile_is_not_deleted() -> Result<()> {
    let Some(db) = TestDb::create().await? else {
        return Ok(());
    };
    let pool = &db.pool;
    let covers = tempfile::tempdir()?;
    let mut files = manager(pool, covers.path()).await?;
    let (file, _) = post("q", "a8f3c29d10e4", b"PAR2\0PKT");
    let claimed = release(pool, "d1", &[file]).await?;
    let id = claimed.id;
    let stored = nzb::read(pool, "d1").await?.expect("stored NZB");
    let mut context = ReleaseContext::new(claimed);

    // A newer NZB was installed after this attempt read its own.
    context.nzb_source_digest = Some(nzb::digest(b"<nzb>older</nzb>"));
    assert!(!files.delete_release_if_current(&context).await?);
    assert!(exists(pool, id).await?);

    // Another worker took the claim over.
    context.nzb_source_digest = Some(nzb::digest(&stored));
    sqlx::query("UPDATE releases SET additional_pp_claim_token = 'other' WHERE id = ?")
        .bind(id)
        .execute(pool)
        .await?;
    assert!(!files.delete_release_if_current(&context).await?);
    assert!(exists(pool, id).await?);

    sqlx::query("UPDATE releases SET additional_pp_claim_token = ? WHERE id = ?")
        .bind(TOKEN)
        .bind(id)
        .execute(pool)
        .await?;
    assert!(files.delete_release_if_current(&context).await?);
    assert!(!exists(pool, id).await?);
    assert!(nzb::read(pool, "d1").await?.is_none());
    db.drop_database().await
}

#[tokio::test]
async fn a_passworded_archive_is_reported_as_such() -> Result<()> {
    let Some(db) = TestDb::create().await? else {
        return Ok(());
    };
    let pool = &db.pool;
    let tmp = tempfile::tempdir()?;
    // A RAR 4 marker, then a main header with encrypted headers.
    let mut rar = b"Rar!\x1a\x07\x00".to_vec();
    let head = [0x73u8, 0x80, 0x00, 13, 0, 0, 0, 0, 0, 0, 0];
    let crc = (crc32fast::hash(&head) & 0xFFFF) as u16;
    rar.extend_from_slice(&crc.to_le_bytes());
    rar.extend_from_slice(&head);
    rar.extend_from_slice(&[0x55; 300]);
    let (file, articles) = post("r", "Movie.2023.1080p.BluRay.x264-GRP.rar", &rar);
    let claimed = release(pool, "d1", &[file]).await?;
    let id = claimed.id;

    let (result, context) = process(pool, claimed, articles, tmp.path(), &Media::default()).await?;

    assert_eq!(result.outcome, Outcome::Passworded, "{result:?}");
    assert!(result.is_successful());
    assert_eq!(context.password_status, 1);
    let status: i64 =
        sqlx::query_scalar("SELECT CAST(passwordstatus AS SIGNED) FROM releases WHERE id = ?")
            .bind(id)
            .fetch_one(pool)
            .await?;
    assert_eq!(status, 1);
    db.drop_database().await
}

#[tokio::test]
async fn a_missing_nzb_fails_without_deleting() -> Result<()> {
    let Some(db) = TestDb::create().await? else {
        return Ok(());
    };
    let pool = &db.pool;
    let tmp = tempfile::tempdir()?;
    let claimed = release(pool, "e1", &[]).await?;
    let id = claimed.id;

    let (result, _) = process(pool, claimed, Vec::new(), tmp.path(), &Media::default()).await?;

    assert_eq!(result.outcome, Outcome::Failed);
    assert!(
        result.reason.contains("NZB is missing"),
        "{}",
        result.reason
    );
    assert!(exists(pool, id).await?);
    db.drop_database().await
}

#[tokio::test]
async fn an_unusable_workspace_is_reported() -> Result<()> {
    let Some(db) = TestDb::create().await? else {
        return Ok(());
    };
    let pool = &db.pool;
    let tmp = tempfile::tempdir()?;
    let not_a_directory = tmp.path().join("file");
    std::fs::write(&not_a_directory, b"x")?;
    let claimed = release(pool, "f1", &[]).await?;

    let (result, _) = process(
        pool,
        claimed,
        Vec::new(),
        &not_a_directory,
        &Media::default(),
    )
    .await?;

    assert_eq!(result.outcome, Outcome::TemporaryWorkspaceUnavailable);
    assert!(!result.reason.is_empty());
    db.drop_database().await
}

/// A RAR 4 block: header CRC, type, flags and size, then the body and any data.
fn rar_block(kind: u8, flags: u16, body: &[u8], data: &[u8]) -> Vec<u8> {
    let mut head = vec![kind];
    head.extend_from_slice(&flags.to_le_bytes());
    head.extend_from_slice(&((7 + body.len()) as u16).to_le_bytes());
    head.extend_from_slice(body);
    let mut block = ((crc32fast::hash(&head) & 0xFFFF) as u16)
        .to_le_bytes()
        .to_vec();
    block.extend_from_slice(&head);
    block.extend_from_slice(data);
    block
}

/// A single-volume RAR 4 archive storing `files` uncompressed.
fn stored_rar(files: &[(&str, &[u8])]) -> Vec<u8> {
    let mut rar = b"Rar!\x1a\x07\x00".to_vec();
    rar.extend(rar_block(0x73, 0, &[0; 6], &[]));
    for (name, data) in files {
        let mut body = Vec::new();
        body.extend_from_slice(&(data.len() as u32).to_le_bytes());
        body.extend_from_slice(&(data.len() as u32).to_le_bytes());
        body.push(3);
        body.extend_from_slice(&crc32fast::hash(data).to_le_bytes());
        body.extend_from_slice(&0x5A2B_0000u32.to_le_bytes());
        body.extend_from_slice(&[29, 0x30]);
        body.extend_from_slice(&(name.len() as u16).to_le_bytes());
        body.extend_from_slice(&0x20u32.to_le_bytes());
        body.extend_from_slice(name.as_bytes());
        rar.extend(rar_block(0x74, 0x8000, &body, data));
    }
    rar.extend(rar_block(0x7B, 0, &[], &[]));
    rar
}

const NFO_TEXT: &[u8] = b"\xb0\xb1\xb2 GROUP \xb2\xb1\xb0\r\n  Release Date ....: 2021-05-01\r\n  \
    Source ..........: UHD BluRay\r\n  Video ...........: HEVC 10bit HDR\r\n  \
    Audio ...........: English TrueHD Atmos 7.1\r\n  \
    IMDB ............: https://www.imdb.com/title/tt0000001/\r\n";

#[tokio::test]
async fn nfos_and_pictures_are_copied_out_of_a_downloaded_archive() -> Result<()> {
    let Some(db) = TestDb::create().await? else {
        return Ok(());
    };
    let pool = &db.pool;
    let tmp = tempfile::tempdir()?;
    let picture = [0xFFu8, 0xD8, 0xFF, 0xE0, 1, 2, 3, 4];
    let rar = stored_rar(&[
        ("Movie.2023.1080p.BluRay.x264-GRP.nfo", NFO_TEXT),
        ("Screens/screens.jpg", &picture),
        ("cover.jpg", &[0xFF, 0xD8, 0xFF, 0xE1]),
    ]);
    let (file, articles) = post("s", "Movie.2023.1080p.BluRay.x264-GRP.rar", &rar);
    let claimed = release(pool, "g1", &[file]).await?;
    let id = claimed.id;
    let media = Media::default();

    let (result, context) = process(pool, claimed, articles, tmp.path(), &media).await?;

    assert_eq!(result.outcome, Outcome::Completed, "{result:?}");
    assert!(!context.release_has_no_nfo);
    let stored: i64 = sqlx::query_scalar(
        "SELECT COUNT(*) FROM release_nfos n JOIN releases r ON r.id = n.releases_id \
         WHERE r.id = ? AND r.nfostatus = 1",
    )
    .bind(id)
    .fetch_one(pool)
    .await?;
    assert_eq!(stored, 1);
    // Both pictures were tried, the screen capture first; neither was usable.
    let tried: Vec<_> = media
        .0
        .lock()
        .unwrap()
        .iter()
        .filter(|(step, _)| *step == "usable_image")
        .map(|(_, len)| *len)
        .collect();
    assert_eq!(tried, vec![picture.len(), 4]);
    assert!(!context.found_jpg_sample);
    db.drop_database().await
}
