//! `MediaExtractionService::getMediaInfo`: mediainfo's report on a downloaded video, stored as
//! `media_infos` (`MediaInfo::addData`) and as `video_data`, `audio_data` and
//! `release_subtitles` (`ReleaseExtraService::addFromXml`).
//!
//! mediainfo runs as php-mediainfo ran it, `mediainfo <file> -f --OUTPUT=OLDXML`, under the
//! 60-second limit Symfony's `Process` applied by default.

pub mod parse;
pub mod rows;

use std::path::Path;
use std::process::Stdio;
use std::time::Duration;

use anyhow::{Result, anyhow, bail};
use nntmux_namefix::update::ReleaseSearch;
use sqlx::MySqlPool;
use tokio::process::Command;

use rows::{Insert, MediaInfoRow, Value};

/// Symfony `Process`'s default timeout.
const MEDIAINFO_TIMEOUT: Duration = Duration::from_secs(60);

/// mediainfo's old XML report on a file.
pub async fn report(mediainfo: &Path, file: &Path) -> Result<Vec<u8>> {
    let mut command = Command::new(mediainfo);
    command
        .arg(file)
        .arg("-f")
        .arg("--OUTPUT=OLDXML")
        .env("LANG", "C")
        .stdin(Stdio::null())
        .stdout(Stdio::piped())
        .stderr(Stdio::piped())
        .kill_on_drop(true);
    let output = tokio::time::timeout(MEDIAINFO_TIMEOUT, command.output())
        .await
        .map_err(|_| anyhow!("mediainfo timed out"))??;
    if !output.status.success() {
        bail!(
            "mediainfo failed: {}",
            String::from_utf8_lossy(&output.stderr).trim()
        );
    }
    Ok(output.stdout)
}

fn bind<'q>(
    query: sqlx::query::Query<'q, sqlx::MySql, sqlx::mysql::MySqlArguments>,
    value: &Value,
) -> sqlx::query::Query<'q, sqlx::MySql, sqlx::mysql::MySqlArguments> {
    match value {
        Value::Str(text) => query.bind(text.clone()),
        Value::Int(number) => query.bind(*number),
        Value::Null | Value::Array => query.bind(None::<String>),
    }
}

/// `MediaInfo::addData`: the first report kept per release.
pub async fn add_media_info(pool: &MySqlPool, release_id: i64, row: &MediaInfoRow) -> Result<()> {
    let exists: Option<i64> =
        sqlx::query_scalar("SELECT 1 FROM media_infos WHERE releases_id = ? LIMIT 1")
            .bind(release_id)
            .fetch_optional(pool)
            .await?;
    if exists.is_some() {
        return Ok(());
    }
    if row.has_array() {
        bail!("a media info value is an array");
    }
    let query = sqlx::query(
        "INSERT IGNORE INTO media_infos (releases_id, movie_name, file_name, unique_id, \
         created_at, updated_at) VALUES (?, ?, ?, ?, NOW(), NOW())",
    )
    .bind(release_id);
    let query = bind(query, &row.movie_name);
    let query = bind(query, &row.file_name);
    let query = bind(query, &row.unique_id);
    query.execute(pool).await?;
    Ok(())
}

/// `addFromXml`'s inserts: each skipped when its row exists, failing at the first that can't be
/// bound, with the earlier ones kept.
pub async fn add_extra(pool: &MySqlPool, release_id: i64, inserts: &[Insert]) -> Result<()> {
    for insert in inserts {
        let exists: Option<i64> = match insert {
            Insert::Video(_) => {
                sqlx::query_scalar("SELECT 1 FROM video_data WHERE releases_id = ? LIMIT 1")
                    .bind(release_id)
                    .fetch_optional(pool)
                    .await?
            }
            Insert::Audio(row) => {
                let query = sqlx::query_scalar(
                    "SELECT 1 FROM audio_data WHERE releases_id = ? AND audioid = ? LIMIT 1",
                )
                .bind(release_id);
                bind_scalar(query, &row.audioid)
                    .fetch_optional(pool)
                    .await?
            }
            Insert::Subtitle(row) => {
                let query = sqlx::query_scalar(
                    "SELECT 1 FROM release_subtitles WHERE releases_id = ? AND subsid = ? LIMIT 1",
                )
                .bind(release_id);
                bind_scalar(query, &row.subsid).fetch_optional(pool).await?
            }
        };
        if exists.is_some() {
            continue;
        }
        if insert.has_array() {
            bail!("a media value is an array");
        }
        let query = match insert {
            Insert::Video(row) => {
                let query = sqlx::query(
                    "INSERT IGNORE INTO video_data (releases_id, containerformat, overallbitrate, \
                     videoduration, videoformat, videocodec, videowidth, videoheight, videoaspect, \
                     videoframerate, videolibrary) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                )
                .bind(release_id);
                [
                    &row.containerformat,
                    &row.overallbitrate,
                    &row.videoduration,
                    &row.videoformat,
                    &row.videocodec,
                    &row.videowidth,
                    &row.videoheight,
                    &row.videoaspect,
                    &row.videoframerate,
                    &row.videolibrary,
                ]
                .into_iter()
                .fold(query, bind)
            }
            Insert::Audio(row) => {
                let query = sqlx::query(
                    "INSERT IGNORE INTO audio_data (releases_id, audioid, audioformat, audiomode, \
                     audiobitratemode, audiobitrate, audiochannels, audiosamplerate, audiolibrary, \
                     audiolanguage, audiotitle) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                )
                .bind(release_id);
                [
                    &row.audioid,
                    &row.audioformat,
                    &row.audiomode,
                    &row.audiobitratemode,
                    &row.audiobitrate,
                    &row.audiochannels,
                    &row.audiosamplerate,
                    &row.audiolibrary,
                    &row.audiolanguage,
                    &row.audiotitle,
                ]
                .into_iter()
                .fold(query, bind)
            }
            Insert::Subtitle(row) => {
                let query = sqlx::query(
                    "INSERT IGNORE INTO release_subtitles (releases_id, subsid, subslanguage) \
                     VALUES (?, ?, ?)",
                )
                .bind(release_id);
                bind(bind(query, &row.subsid), &row.subslanguage)
            }
        };
        query.execute(pool).await?;
    }
    Ok(())
}

fn bind_scalar<'q>(
    query: sqlx::query::QueryScalar<'q, sqlx::MySql, i64, sqlx::mysql::MySqlArguments>,
    value: &Value,
) -> sqlx::query::QueryScalar<'q, sqlx::MySql, i64, sqlx::mysql::MySqlArguments> {
    match value {
        Value::Str(text) => query.bind(text.clone()),
        Value::Int(number) => query.bind(*number),
        Value::Null | Value::Array => query.bind(None::<String>),
    }
}

/// Stores a parsed report: `addData`, then `addFromXml`, then a search refresh, as
/// `getMediaInfo` did. An error leaves what was written before it.
pub async fn store(
    pool: &MySqlPool,
    search: &dyn ReleaseSearch,
    release_id: i64,
    report: &[u8],
) -> Result<()> {
    let container = parse::container(report)?;
    if let Some(row) = rows::media_info_row(&container) {
        add_media_info(pool, release_id, &row).await?;
    }
    let extra = rows::extra(&container);
    add_extra(pool, release_id, &extra.inserts).await?;
    if extra.failed {
        bail!("a warning ended the media pass");
    }
    search.update_release(release_id).await;
    Ok(())
}

/// `getMediaInfo`: true when a report was read and stored.
pub async fn get_media_info(
    pool: &MySqlPool,
    search: &dyn ReleaseSearch,
    mediainfo: Option<&Path>,
    file: &Path,
    release_id: i64,
) -> bool {
    let Some(mediainfo) = mediainfo else {
        return false;
    };
    if !file.is_file() {
        return false;
    }
    let result = async {
        let output = report(mediainfo, file).await?;
        store(pool, search, release_id, &output).await
    }
    .await;
    match result {
        Ok(()) => true,
        Err(error) => {
            eprintln!("release {release_id}: media info not stored: {error:#}");
            false
        }
    }
}
