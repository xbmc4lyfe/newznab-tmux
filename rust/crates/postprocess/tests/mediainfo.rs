//! Storing mediainfo reports against a real database, and running a scripted mediainfo.

use std::os::unix::fs::PermissionsExt;
use std::path::Path;
use std::sync::Mutex;

use anyhow::Result;
use async_trait::async_trait;
use nntmux_namefix::update::ReleaseSearch;
use nntmux_postprocess::mediainfo;
use nntmux_testkit::TestDb;
use sqlx::MySqlPool;

#[derive(Default)]
struct Recorder(Mutex<Vec<i64>>);

#[async_trait]
impl ReleaseSearch for Recorder {
    async fn update_release(&self, release_id: i64) {
        self.0.lock().expect("unpoisoned").push(release_id);
    }
}

fn fixture(name: &str) -> Vec<u8> {
    std::fs::read(format!(
        "{}/tests/fixtures/mediainfo/{name}",
        env!("CARGO_MANIFEST_DIR")
    ))
    .expect("fixture")
}

async fn release(pool: &MySqlPool, guid: &str) -> Result<i64> {
    Ok(sqlx::query(
        "INSERT INTO releases (name, searchname, totalpart, groups_id, fromname, size, postdate, \
         adddate, guid, leftguid, categories_id) VALUES ('n', 's', 1, 1, 'f', 5000000, \
         '2026-01-01 00:00:00', '2026-01-01 00:00:00', ?, ?, 10)",
    )
    .bind(guid)
    .bind(&guid[..1])
    .execute(pool)
    .await?
    .last_insert_id() as i64)
}

/// (media_infos, video_data, audio_data, release_subtitles) row counts.
async fn counts(pool: &MySqlPool, id: i64) -> Result<(i64, i64, i64, i64)> {
    Ok(sqlx::query_as(
        "SELECT (SELECT COUNT(*) FROM media_infos WHERE releases_id = ?), \
         (SELECT COUNT(*) FROM video_data WHERE releases_id = ?), \
         (SELECT COUNT(*) FROM audio_data WHERE releases_id = ?), \
         (SELECT COUNT(*) FROM release_subtitles WHERE releases_id = ?)",
    )
    .bind(id)
    .bind(id)
    .bind(id)
    .bind(id)
    .fetch_one(pool)
    .await?)
}

#[tokio::test]
async fn a_remux_report_is_stored_once() -> Result<()> {
    let Some(db) = TestDb::create().await? else {
        return Ok(());
    };
    let pool = &db.pool;
    let search = Recorder::default();
    let id = release(pool, "a1").await?;
    mediainfo::store(pool, &search, id, &fixture("remux_2160p.xml")).await?;
    assert_eq!(counts(pool, id).await?, (1, 1, 2, 3));
    let (movie, file, unique): (Option<String>, Option<String>, Option<String>) = sqlx::query_as(
        "SELECT movie_name, file_name, unique_id FROM media_infos WHERE releases_id = ?",
    )
    .bind(id)
    .fetch_one(pool)
    .await?;
    assert_eq!(movie.as_deref(), Some("Some Movie"));
    assert_eq!(
        file.as_deref(),
        Some("Some.Movie.2021.2160p.UHD.BluRay.REMUX.HDR.HEVC.TrueHD.7.1.Atmos-GROUP")
    );
    assert_eq!(
        unique.as_deref(),
        Some("186135458392113489023851745671339098311")
    );
    let video: (String, i64, i64, String, f64, String) = sqlx::query_as(
        "SELECT videoformat, videowidth, videoheight, videoduration, videoframerate, overallbitrate \
         FROM video_data WHERE releases_id = ?",
    )
    .bind(id)
    .fetch_one(pool)
    .await?;
    assert_eq!(
        video,
        (
            "HEVC".to_owned(),
            3840,
            2160,
            "02h:10m:45s".to_owned(),
            23.976,
            "58.0 Mb/s".to_owned()
        )
    );
    let audio: Vec<(i64, String, String)> = sqlx::query_as(
        "SELECT CAST(audioid AS SIGNED), audiolanguage, audiochannels FROM audio_data \
         WHERE releases_id = ? ORDER BY audioid",
    )
    .bind(id)
    .fetch_all(pool)
    .await?;
    assert_eq!(
        audio,
        [
            (2, "English".to_owned(), "8".to_owned()),
            (3, "French".to_owned(), "6".to_owned())
        ]
    );
    let subtitles: Vec<String> = sqlx::query_scalar(
        "SELECT subslanguage FROM release_subtitles WHERE releases_id = ? ORDER BY subsid",
    )
    .bind(id)
    .fetch_all(pool)
    .await?;
    // PHP indexed a lone language string, so `de` stored `e`, and no language stored `n`.
    assert_eq!(subtitles, ["English", "n", "e"]);
    assert_eq!(*search.0.lock().expect("unpoisoned"), [id]);

    // A second report changes nothing that is already stored.
    mediainfo::store(pool, &search, id, &fixture("webdl_1080p.xml")).await?;
    assert_eq!(counts(pool, id).await?, (1, 1, 3, 3));
    let format: String =
        sqlx::query_scalar("SELECT videoformat FROM video_data WHERE releases_id = ?")
            .bind(id)
            .fetch_one(pool)
            .await?;
    assert_eq!(format, "HEVC");
    db.drop_database().await
}

#[tokio::test]
async fn a_value_that_cannot_be_bound_stops_the_pass() -> Result<()> {
    let Some(db) = TestDb::create().await? else {
        return Ok(());
    };
    let pool = &db.pool;
    let search = Recorder::default();
    // An empty movie name is an array, so nothing is stored and search isn't refreshed.
    let id = release(pool, "a1").await?;
    assert!(
        mediainfo::store(pool, &search, id, &fixture("edge_cases.xml"))
            .await
            .is_err()
    );
    assert_eq!(counts(pool, id).await?, (0, 0, 0, 0));
    // Once a media info row exists, its array isn't met, and the rest is stored.
    sqlx::query("INSERT INTO media_infos (releases_id) VALUES (?)")
        .bind(id)
        .execute(pool)
        .await?;
    mediainfo::store(pool, &search, id, &fixture("edge_cases.xml")).await?;
    assert_eq!(counts(pool, id).await?, (1, 1, 2, 1));
    // A parse failure stores nothing.
    let other = release(pool, "b1").await?;
    assert!(
        mediainfo::store(pool, &search, other, &fixture("typeerrors.xml"))
            .await
            .is_err()
    );
    assert_eq!(counts(pool, other).await?, (0, 0, 0, 0));
    assert_eq!(*search.0.lock().expect("unpoisoned"), [id]);
    db.drop_database().await
}

fn script(directory: &Path, body: &str) -> Result<std::path::PathBuf> {
    let path = directory.join("mediainfo");
    std::fs::write(&path, format!("#!/bin/sh\n{body}\n"))?;
    std::fs::set_permissions(&path, std::fs::Permissions::from_mode(0o755))?;
    Ok(path)
}

#[tokio::test]
async fn a_scripted_mediainfo_is_run_with_php_mediainfos_arguments() -> Result<()> {
    let Some(db) = TestDb::create().await? else {
        return Ok(());
    };
    let pool = &db.pool;
    let search = Recorder::default();
    let scratch = tempfile::tempdir()?;
    let video = scratch.path().join("x.mkv");
    std::fs::write(&video, b"not really a video")?;
    let report = format!(
        "{}/tests/fixtures/mediainfo/webdl_1080p.xml",
        env!("CARGO_MANIFEST_DIR")
    );
    // The script checks its arguments before answering.
    let good = script(
        scratch.path(),
        &format!(
            "[ \"$1\" = \"{}\" ] && [ \"$2\" = -f ] && [ \"$3\" = --OUTPUT=OLDXML ] || exit 3\ncat '{report}'",
            video.display()
        ),
    )?;
    let id = release(pool, "a1").await?;
    assert!(mediainfo::get_media_info(pool, &search, Some(&good), &video, id).await);
    assert_eq!(counts(pool, id).await?, (1, 1, 1, 0));

    let other = release(pool, "b1").await?;
    let failing = script(scratch.path(), "echo broken >&2\nexit 1")?;
    assert!(!mediainfo::get_media_info(pool, &search, Some(&failing), &video, other).await);
    assert!(
        !mediainfo::get_media_info(
            pool,
            &search,
            Some(&good),
            &scratch.path().join("gone"),
            other
        )
        .await
    );
    assert!(!mediainfo::get_media_info(pool, &search, None, &video, other).await);
    assert_eq!(counts(pool, other).await?, (0, 0, 0, 0));
    db.drop_database().await
}
