//! Shows, episodes and aliases in the database. Port of the storage half of `BaseVideoProvider`
//! and `AbstractTvProvider`.

use std::collections::{HashMap, HashSet};
use std::time::Duration;

use anyhow::Result;
use chrono::{DateTime, NaiveDate};
use md5::{Digest, Md5};
use nntmux_search::TvShowDocument;
use sqlx::{MySql, MySqlPool, QueryBuilder, Row};
use tracing::error;

use super::parse::{normalize_imdb, normalize_text};
use crate::php::{ireplace_ascii, mb_substr, trim};
use crate::services::Services;

pub const TYPE_TV: i64 = 0;

pub const SOURCE_NONE: i64 = 0;
pub const SOURCE_TVDB: i64 = 1;
pub const SOURCE_TVMAZE: i64 = 2;
pub const SOURCE_TMDB: i64 = 3;
pub const SOURCE_TRAKT: i64 = 4;

const EPISODE_LOCK: Duration = Duration::from_secs(120);

/// A site's ID column on `videos`.
#[derive(Clone, Copy, Debug, PartialEq, Eq)]
pub enum Site {
    Tvdb,
    Trakt,
    Tvrage,
    Tvmaze,
    Imdb,
    Tmdb,
}

impl Site {
    pub fn column(self) -> &'static str {
        match self {
            Self::Tvdb => "tvdb",
            Self::Trakt => "trakt",
            Self::Tvrage => "tvrage",
            Self::Tvmaze => "tvmaze",
            Self::Imdb => "imdb",
            Self::Tmdb => "tmdb",
        }
    }
}

/// A show as a provider formats it.
#[derive(Clone, Debug, Default, PartialEq)]
pub struct Show {
    pub title: String,
    pub summary: String,
    pub started: String,
    pub publisher: String,
    pub country: String,
    pub poster: String,
    /// Tried when the poster can't be saved. Only the add-show page uses it.
    pub fallback_poster: String,
    /// `videos.type`, 0 for TV.
    pub kind: i64,
    pub source: i64,
    pub imdb: String,
    pub tvdb: i64,
    pub trakt: i64,
    pub tvrage: i64,
    pub tvmaze: i64,
    pub tmdb: i64,
    pub aliases: Vec<String>,
    pub localzone: String,
}

/// An episode as a provider formats it.
#[derive(Clone, Debug, Default, PartialEq, Eq)]
pub struct Episode {
    pub title: String,
    pub series: i64,
    pub episode: i64,
    pub firstaired: String,
    pub summary: String,
}

impl Episode {
    pub fn new(
        title: String,
        series: i64,
        episode: i64,
        firstaired: String,
        summary: String,
    ) -> Self {
        Self {
            title,
            series,
            episode,
            firstaired,
            summary,
        }
    }
}

struct EpisodeRow {
    series: i64,
    episode: i64,
    se_complete: String,
    title: String,
    firstaired: Option<String>,
    summary: String,
}

fn normalize_airdate(value: &str) -> Option<String> {
    let value = normalize_text(value);
    if value.is_empty() || value.starts_with("0000") {
        return None;
    }
    if let Ok(date) = DateTime::parse_from_rfc3339(&value) {
        return Some(date.date_naive().format("%Y-%m-%d").to_string());
    }
    let date = value.get(..10).unwrap_or(&value);
    NaiveDate::parse_from_str(date, "%Y-%m-%d")
        .ok()
        .map(|date| date.format("%Y-%m-%d").to_string())
}

fn episode_row(episode: &Episode) -> Option<EpisodeRow> {
    let series = episode.series.max(0);
    let number = episode.episode.max(0);
    let firstaired = normalize_airdate(&episode.firstaired);
    // An unnumbered episode without an airdate can't be told apart from any other one.
    if number == 0 && firstaired.is_none() {
        return None;
    }
    Some(EpisodeRow {
        series,
        episode: number,
        se_complete: mb_substr(&format!("S{series:02}E{number:02}"), 10),
        title: mb_substr(&normalize_text(&episode.title), 180),
        firstaired,
        summary: normalize_text(&episode.summary),
    })
}

/// Numbered episodes are keyed by season and episode, unnumbered specials by their airdate.
fn episode_key(series: i64, episode: i64, firstaired: &str) -> String {
    if episode > 0 {
        format!("{series}x{episode}")
    } else {
        format!("0x0@{}", firstaired.get(..10).unwrap_or(firstaired))
    }
}

fn country_cased(country: &str) -> Option<String> {
    let country = trim(country);
    if country.is_empty() {
        return Some(String::new());
    }
    if country.len() == 2 {
        let code = country.to_ascii_uppercase();
        return Some(if code == "UK" { "GB".to_owned() } else { code });
    }
    None
}

/// `normalizeCountry`: a two-letter code for a code or a country name, nothing when unknown.
pub async fn normalize_country(db: &MySqlPool, country: &str) -> Result<String> {
    if let Some(code) = country_cased(country) {
        return Ok(code);
    }
    let country = trim(country);
    if country.len() < 3 {
        return Ok(String::new());
    }
    let code: Option<String> = sqlx::query_scalar(
        "SELECT iso_3166_2 FROM countries WHERE full_name = ? OR name = ? LIMIT 1",
    )
    .bind(country)
    .bind(country)
    .fetch_optional(db)
    .await?;
    Ok(code.unwrap_or_default())
}

pub async fn site_id_of(db: &MySqlPool, site: Site, video_id: i64) -> Result<i64> {
    let column = site.column();
    let statement = format!("SELECT CAST({column} AS SIGNED) FROM videos WHERE id = ?");
    let id: Option<i64> = sqlx::query_scalar(sqlx::AssertSqlSafe(statement))
        .bind(video_id)
        .fetch_optional(db)
        .await?;
    Ok(id.unwrap_or(0))
}

pub async fn local_zone(db: &MySqlPool, video_id: i64) -> Result<String> {
    let zone: Option<String> =
        sqlx::query_scalar("SELECT localzone FROM tv_info WHERE videos_id = ?")
            .bind(video_id)
            .fetch_optional(db)
            .await?;
    Ok(normalize_text(&zone.unwrap_or_default()))
}

/// The ids `getAllSiteIdsFromVideoID` reads.
#[derive(Clone, Debug, Default, PartialEq, Eq)]
pub struct SiteIds {
    pub trakt: i64,
    pub tmdb: i64,
    pub tvdb: i64,
    pub imdb: String,
}

pub async fn site_ids(db: &MySqlPool, video_id: i64) -> Result<SiteIds> {
    let row = sqlx::query(
        "SELECT CAST(trakt AS SIGNED) AS trakt, CAST(tmdb AS SIGNED) AS tmdb, \
         CAST(tvdb AS SIGNED) AS tvdb, imdb FROM videos WHERE id = ?",
    )
    .bind(video_id)
    .fetch_optional(db)
    .await?;
    let Some(row) = row else {
        return Ok(SiteIds::default());
    };
    Ok(SiteIds {
        trakt: row.try_get("trakt")?,
        tmdb: row.try_get("tmdb")?,
        tvdb: row.try_get("tvdb")?,
        imdb: row.try_get("imdb")?,
    })
}

pub async fn title_exact(db: &MySqlPool, title: &str, kind: i64) -> Result<i64> {
    if title.is_empty() {
        return Ok(0);
    }
    let id: Option<i64> = sqlx::query_scalar(
        "SELECT CAST(id AS SIGNED) FROM videos WHERE title = ? AND type = ? ORDER BY id LIMIT 1",
    )
    .bind(title)
    .bind(kind)
    .fetch_optional(db)
    .await?;
    if let Some(id) = id {
        return Ok(id);
    }
    let id: Option<i64> = sqlx::query_scalar(
        "SELECT CAST(v.id AS SIGNED) FROM videos v \
         JOIN videos_aliases a ON v.id = a.videos_id \
         WHERE a.title = ? AND v.type = ? ORDER BY v.id LIMIT 1",
    )
    .bind(title)
    .bind(kind)
    .fetch_optional(db)
    .await?;
    Ok(id.unwrap_or(0))
}

async fn title_loose(db: &MySqlPool, pattern: &str, kind: i64) -> Result<i64> {
    let pattern = pattern.trim_end_matches('%');
    if pattern.is_empty() {
        return Ok(0);
    }
    let id: Option<i64> = sqlx::query_scalar(
        "SELECT CAST(id AS SIGNED) FROM videos WHERE title LIKE ? AND type = ? \
         ORDER BY LENGTH(title) LIMIT 1",
    )
    .bind(pattern)
    .bind(kind)
    .fetch_optional(db)
    .await?;
    if let Some(id) = id {
        return Ok(id);
    }
    let id: Option<i64> = sqlx::query_scalar(
        "SELECT CAST(v.id AS SIGNED) FROM videos v \
         JOIN videos_aliases a ON v.id = a.videos_id \
         WHERE a.title LIKE ? AND v.type = ? ORDER BY LENGTH(a.title) LIMIT 1",
    )
    .bind(pattern)
    .bind(kind)
    .fetch_optional(db)
    .await?;
    Ok(id.unwrap_or(0))
}

async fn alternative_title_exact(db: &MySqlPool, title: &str, kind: i64) -> Result<i64> {
    if title.is_empty() {
        return Ok(0);
    }
    let id: Option<i64> = sqlx::query_scalar(
        "SELECT CAST(id AS SIGNED) FROM videos WHERE type = ? \
         AND (REPLACE(title, '''', '') = ? OR REPLACE(title, ':', '') = ?) \
         ORDER BY id LIMIT 1",
    )
    .bind(kind)
    .bind(title)
    .bind(title)
    .fetch_optional(db)
    .await?;
    Ok(id.unwrap_or(0))
}

fn loose_pattern(title: &str) -> String {
    let mut pattern = "%".to_owned();
    for piece in title.split(' ') {
        pattern.push_str(&piece.replace(['\'', '!'], ""));
        pattern.push('%');
    }
    pattern
}

/// `getByTitle`: an exact title, then the variants release names tend to spell differently.
pub async fn by_title(db: &MySqlPool, title: &str, kind: i64) -> Result<i64> {
    let found = title_exact(db, title, kind).await?;
    if found != 0 {
        return Ok(found);
    }
    if let Some(open) = title.rfind(" (")
        && title.ends_with(')')
        && title.len() == open + 7
        && title[open + 2..title.len() - 1]
            .bytes()
            .all(|byte| byte.is_ascii_digit())
    {
        let found = title_exact(db, trim(&title[..open]), kind).await?;
        if found != 0 {
            return Ok(found);
        }
    }
    let found = alternative_title_exact(db, title, kind).await?;
    if found != 0 {
        return Ok(found);
    }
    let ampersand = ireplace_ascii(title, " and ", " & ");
    if ampersand != title {
        let found = title_exact(db, &ampersand, kind).await?;
        if found != 0 {
            return Ok(found);
        }
        let found = title_loose(db, &loose_pattern(&ampersand), kind).await?;
        if found != 0 {
            return Ok(found);
        }
    }
    // Some words are spelled two ways, like theatre and theater.
    let respelled = ireplace_ascii(title, "er", "re");
    if respelled != title {
        let found = title_exact(db, &respelled, kind).await?;
        if found != 0 {
            return Ok(found);
        }
        let found = title_loose(db, &loose_pattern(&respelled), kind).await?;
        if found != 0 {
            return Ok(found);
        }
    } else if title.split(' ').count() > 1 {
        // One word only risks wrong matches. This finds "Zorro (1990)" from "Zorro 1990".
        let found = title_loose(db, &loose_pattern(title), kind).await?;
        if found != 0 {
            return Ok(found);
        }
    }
    Ok(0)
}

/// `getBySeasonEp`: by season and episode (season 0 holds specials), or by airdate.
pub async fn by_season_episode(
    db: &MySqlPool,
    video_id: i64,
    season: i64,
    episode: i64,
    airdate: &str,
) -> Result<Option<i64>> {
    if video_id <= 0 {
        return Ok(None);
    }
    if season >= 0 && episode > 0 {
        return Ok(sqlx::query_scalar(
            "SELECT CAST(id AS SIGNED) FROM tv_episodes \
             WHERE videos_id = ? AND series = ? AND episode = ? ORDER BY id LIMIT 1",
        )
        .bind(video_id)
        .bind(season)
        .bind(episode)
        .fetch_optional(db)
        .await?);
    }
    if airdate.is_empty() {
        return Ok(None);
    }
    let Some(date) = normalize_airdate(airdate) else {
        return Ok(None);
    };
    Ok(sqlx::query_scalar(
        "SELECT CAST(id AS SIGNED) FROM tv_episodes \
         WHERE videos_id = ? AND DATE(firstaired) = ? ORDER BY id LIMIT 1",
    )
    .bind(video_id)
    .bind(date)
    .fetch_optional(db)
    .await?)
}

pub async fn has_episodes(db: &MySqlPool, video_id: i64) -> Result<bool> {
    let found: Option<i64> =
        sqlx::query_scalar("SELECT 1 FROM tv_episodes WHERE videos_id = ? LIMIT 1")
            .bind(video_id)
            .fetch_optional(db)
            .await?;
    Ok(found.is_some())
}

pub async fn has_cover(db: &MySqlPool, video_id: i64) -> Result<bool> {
    let image: Option<i8> = sqlx::query_scalar("SELECT image FROM tv_info WHERE videos_id = ?")
        .bind(video_id)
        .fetch_optional(db)
        .await?;
    Ok(image == Some(1))
}

/// Saves a show's poster and marks it found.
pub async fn save_poster(services: &Services, video_id: i64, url: &str) -> Result<bool> {
    if video_id <= 0 || url.is_empty() {
        return Ok(false);
    }
    let saved = services
        .images
        .save_remote(
            &video_id.to_string(),
            url,
            "tvshows",
            crate::images::Profile::Original,
        )
        .await;
    if saved {
        sqlx::query("UPDATE tv_info SET image = 1 WHERE videos_id = ?")
            .bind(video_id)
            .execute(&services.db)
            .await?;
    }
    Ok(saved)
}

/// Inserts the aliases a video doesn't have yet.
pub async fn add_aliases(db: &MySqlPool, video_id: i64, aliases: &[String]) -> Result<()> {
    if video_id <= 0 {
        return Ok(());
    }
    let mut seen = HashSet::new();
    let mut titles = Vec::new();
    for alias in aliases {
        let alias = mb_substr(trim(alias), 180);
        if !alias.is_empty() && seen.insert(alias.to_lowercase()) {
            titles.push(alias);
        }
    }
    if titles.is_empty() {
        return Ok(());
    }
    let mut insert: QueryBuilder<MySql> = QueryBuilder::new(
        "INSERT IGNORE INTO videos_aliases (videos_id, title, created_at, updated_at) ",
    );
    insert.push_values(&titles, |mut row, title| {
        row.push_bind(video_id)
            .push_bind(title)
            .push("NOW()")
            .push("NOW()");
    });
    insert.build().execute(db).await?;
    Ok(())
}

/// `VideoObserver`'s index write. A failure is logged; the sweep repairs the index.
async fn index_show(services: &Services, video_id: i64) {
    let Some(search) = &services.search else {
        return;
    };
    let row = sqlx::query(
        "SELECT title, CAST(tvdb AS SIGNED) AS tvdb, CAST(trakt AS SIGNED) AS trakt, \
         CAST(tvmaze AS SIGNED) AS tvmaze, CAST(tvrage AS SIGNED) AS tvrage, imdb, \
         CAST(tmdb AS SIGNED) AS tmdb, CAST(started AS CHAR) AS started, \
         CAST(type AS SIGNED) AS kind FROM videos WHERE id = ?",
    )
    .bind(video_id)
    .fetch_optional(&services.db)
    .await;
    let document = row.map(|row| {
        row.and_then(|row| {
            Some(TvShowDocument {
                id: video_id,
                title: row.try_get("title").ok()?,
                tvdb: row.try_get("tvdb").ok()?,
                trakt: row.try_get("trakt").ok()?,
                tvmaze: row.try_get("tvmaze").ok()?,
                tvrage: row.try_get("tvrage").ok()?,
                imdb: row.try_get("imdb").ok()?,
                tmdb: row.try_get("tmdb").ok()?,
                started: row
                    .try_get::<Option<String>, _>("started")
                    .ok()?
                    .unwrap_or_default(),
                kind: row.try_get("kind").ok()?,
            })
        })
    });
    match document {
        Ok(Some(document)) => {
            if let Err(failure) = search.insert_tv_show(&document).await {
                error!(video_id, error = %failure, "couldn't index a TV show");
            }
        }
        Ok(None) => {}
        Err(failure) => error!(video_id, error = %failure, "couldn't read a TV show to index"),
    }
}

/// The value bound to `videos.started`. An empty date is the zero date, as Laravel's non-strict
/// connection stored it, because a show may not have a premiere date yet.
fn started_value(started: &str) -> &str {
    if started.is_empty() {
        "0000-00-00 00:00:00"
    } else {
        started
    }
}

/// `add`: inserts a new show, or updates the existing one found by site ID or title. Serialised
/// per title across workers; a lost race falls back to the row the other worker created.
pub async fn add_show(services: &Services, show: &Show) -> Result<i64> {
    let digest = hex::encode(Md5::digest(show.title.to_lowercase().as_bytes()));
    let lock = services
        .cache
        .lock(
            &format!("tv:add:{digest}"),
            Duration::from_secs(30),
            Duration::from_secs(10),
        )
        .await;
    let added = add_or_update(services, show).await;
    if let Some(lock) = lock {
        services.cache.release(lock).await;
    }
    added
}

async fn add_or_update(services: &Services, show: &Show) -> Result<i64> {
    let db = &services.db;
    let country = normalize_country(db, &show.country).await?;
    let existing = find_existing(db, show).await?;
    if existing > 0 {
        update_show(services, existing, show).await?;
        return Ok(existing);
    }
    let title = mb_substr(&show.title, 180);
    let imdb = normalize_imdb(&show.imdb);
    let inserted = sqlx::query(
        "INSERT INTO videos (type, title, countries_id, started, source, tvdb, trakt, tvrage, \
         tvmaze, imdb, tmdb) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
    )
    .bind(show.kind)
    .bind(&title)
    .bind(&country)
    .bind(started_value(&show.started))
    .bind(show.source)
    .bind(show.tvdb)
    .bind(show.trakt)
    .bind(show.tvrage)
    .bind(show.tvmaze)
    .bind(if imdb.is_empty() { "0" } else { &imdb })
    .bind(show.tmdb)
    .execute(db)
    .await;
    let video_id = match inserted {
        Ok(done) => i64::try_from(done.last_insert_id())?,
        Err(failure) => {
            let unique = failure
                .as_database_error()
                .is_some_and(|database| database.is_unique_violation());
            if !unique {
                return Err(failure.into());
            }
            let raced: Option<i64> = sqlx::query_scalar(
                "SELECT CAST(id AS SIGNED) FROM videos WHERE title = ? AND type = ? \
                 AND started = ? AND countries_id = ? FOR UPDATE",
            )
            .bind(&title)
            .bind(show.kind)
            .bind(started_value(&show.started))
            .bind(&country)
            .fetch_optional(db)
            .await?;
            let Some(video_id) = raced else {
                return Err(failure.into());
            };
            insert_info(db, video_id, show).await?;
            update_show(services, video_id, show).await?;
            return Ok(video_id);
        }
    };
    index_show(services, video_id).await;
    insert_info(db, video_id, show).await?;
    add_aliases(db, video_id, &show.aliases).await?;
    Ok(video_id)
}

async fn insert_info(db: &MySqlPool, video_id: i64, show: &Show) -> Result<()> {
    sqlx::query(
        "INSERT IGNORE INTO tv_info (videos_id, summary, publisher, localzone) VALUES (?, ?, ?, ?)",
    )
    .bind(video_id)
    .bind(normalize_text(&show.summary))
    .bind(mb_substr(&normalize_text(&show.publisher), 50))
    .bind(normalize_text(&show.localzone))
    .execute(db)
    .await?;
    Ok(())
}

async fn find_existing(db: &MySqlPool, show: &Show) -> Result<i64> {
    let sites = [
        (Site::Tvdb, show.tvdb),
        (Site::Trakt, show.trakt),
        (Site::Tvrage, show.tvrage),
        (Site::Tvmaze, show.tvmaze),
        (Site::Imdb, normalize_imdb(&show.imdb).parse().unwrap_or(0)),
        (Site::Tmdb, show.tmdb),
    ];
    for (site, id) in sites {
        if id > 0 {
            let column = site.column();
            let statement = format!(
                "SELECT CAST(id AS SIGNED) FROM videos WHERE {column} = ? AND type = ? \
                 ORDER BY id LIMIT 1"
            );
            let found: Option<i64> = sqlx::query_scalar(sqlx::AssertSqlSafe(statement))
                .bind(id)
                .bind(TYPE_TV)
                .fetch_optional(db)
                .await?;
            if let Some(found) = found {
                return Ok(found);
            }
        }
    }
    let found: Option<i64> = sqlx::query_scalar(
        "SELECT CAST(id AS SIGNED) FROM videos WHERE title = ? AND type = ? ORDER BY id LIMIT 1",
    )
    .bind(&show.title)
    .bind(show.kind)
    .fetch_optional(db)
    .await?;
    Ok(found.unwrap_or(0))
}

/// `update`: fills in the video and `tv_info` fields that are still empty.
pub async fn update_show(services: &Services, video_id: i64, show: &Show) -> Result<()> {
    let db = &services.db;
    let row = sqlx::query(
        "SELECT countries_id, CAST(tvdb AS SIGNED) AS tvdb, CAST(trakt AS SIGNED) AS trakt, \
         CAST(tvrage AS SIGNED) AS tvrage, CAST(tvmaze AS SIGNED) AS tvmaze, \
         CAST(tmdb AS SIGNED) AS tmdb, imdb FROM videos WHERE id = ?",
    )
    .bind(video_id)
    .fetch_optional(db)
    .await?;
    let Some(row) = row else {
        return Ok(());
    };
    let mut fields: Vec<(&str, FieldValue)> = Vec::new();
    let country = normalize_country(db, &show.country).await?;
    let current: String = row.try_get("countries_id")?;
    if !country.is_empty() && trim(&current).is_empty() {
        fields.push(("countries_id", FieldValue::Text(country)));
    }
    for (column, id) in [
        ("tvdb", show.tvdb),
        ("trakt", show.trakt),
        ("tvrage", show.tvrage),
        ("tvmaze", show.tvmaze),
        ("tmdb", show.tmdb),
    ] {
        let current: i64 = row.try_get(column)?;
        if id > 0 && current == 0 {
            fields.push((column, FieldValue::Number(id)));
        }
    }
    let imdb = normalize_imdb(&show.imdb);
    let current: String = row.try_get("imdb")?;
    if !imdb.is_empty() && matches!(trim(&current), "" | "0") {
        fields.push(("imdb", FieldValue::Text(imdb)));
    }
    if !fields.is_empty() {
        let mut update: QueryBuilder<MySql> = QueryBuilder::new("UPDATE videos SET ");
        for (index, (column, value)) in fields.iter().enumerate() {
            if index > 0 {
                update.push(", ");
            }
            update.push(*column).push(" = ");
            match value {
                FieldValue::Text(text) => update.push_bind(text.clone()),
                FieldValue::Number(number) => update.push_bind(*number),
            };
        }
        update.push(" WHERE id = ").push_bind(video_id);
        update.build().execute(db).await?;
        index_show(services, video_id).await;
        services.sync_video_releases(video_id).await;
    }
    update_info(db, video_id, show).await?;
    add_aliases(db, video_id, &show.aliases).await
}

enum FieldValue {
    Text(String),
    Number(i64),
}

async fn update_info(db: &MySqlPool, video_id: i64, show: &Show) -> Result<()> {
    let summary = normalize_text(&show.summary);
    let publisher = mb_substr(&normalize_text(&show.publisher), 50);
    let localzone = normalize_text(&show.localzone);
    let row = sqlx::query("SELECT summary, publisher, localzone FROM tv_info WHERE videos_id = ?")
        .bind(video_id)
        .fetch_optional(db)
        .await?;
    let Some(row) = row else {
        insert_info(db, video_id, show).await?;
        return Ok(());
    };
    let fill = |value: String, current: &str| {
        if !value.is_empty() && trim(current).is_empty() {
            value
        } else {
            current.to_owned()
        }
    };
    let (old_summary, old_publisher, old_zone): (String, String, String) = (
        row.try_get("summary")?,
        row.try_get("publisher")?,
        row.try_get("localzone")?,
    );
    let new_summary = fill(summary, &old_summary);
    let new_publisher = fill(publisher, &old_publisher);
    let new_zone = fill(localzone, &old_zone);
    if new_summary != old_summary || new_publisher != old_publisher || new_zone != old_zone {
        sqlx::query(
            "UPDATE tv_info SET summary = ?, publisher = ?, localzone = ? WHERE videos_id = ?",
        )
        .bind(new_summary)
        .bind(new_publisher)
        .bind(new_zone)
        .bind(video_id)
        .execute(db)
        .await?;
    }
    Ok(())
}

/// `addEpisode`: stores one episode and returns its id.
pub async fn add_episode(db: &MySqlPool, video_id: i64, episode: &Episode) -> Result<Option<i64>> {
    if video_id <= 0 {
        return Ok(None);
    }
    let Some(row) = episode_row(episode) else {
        return Ok(None);
    };
    let airdate = row.firstaired.clone().unwrap_or_default();
    if let Some(existing) =
        by_season_episode(db, video_id, row.series, row.episode, &airdate).await?
    {
        return Ok(Some(existing));
    }
    insert_rows(db, video_id, std::slice::from_ref(&row)).await?;
    by_season_episode(db, video_id, row.series, row.episode, &airdate).await
}

/// `addEpisodes`: stores the episodes of a show that aren't stored yet, returning how many were
/// inserted. Serialised per show across workers.
pub async fn add_episodes(services: &Services, video_id: i64, episodes: &[Episode]) -> Result<u64> {
    if video_id <= 0 {
        return Ok(0);
    }
    let lock = services
        .cache
        .lock(
            &format!("tv:episodes:{video_id}"),
            EPISODE_LOCK,
            EPISODE_LOCK,
        )
        .await;
    let inserted = insert_new_episodes(&services.db, video_id, episodes).await;
    if let Some(lock) = lock {
        services.cache.release(lock).await;
    }
    inserted
}

async fn insert_new_episodes(db: &MySqlPool, video_id: i64, episodes: &[Episode]) -> Result<u64> {
    let known_rows = sqlx::query(
        "SELECT CAST(series AS SIGNED) AS series, CAST(episode AS SIGNED) AS episode, \
         CAST(firstaired AS CHAR) AS firstaired FROM tv_episodes WHERE videos_id = ?",
    )
    .bind(video_id)
    .fetch_all(db)
    .await?;
    let mut known = HashMap::with_capacity(known_rows.len());
    for row in known_rows {
        let aired: Option<String> = row.try_get("firstaired")?;
        known.insert(
            episode_key(
                row.try_get("series")?,
                row.try_get("episode")?,
                aired.as_deref().unwrap_or_default(),
            ),
            (),
        );
    }
    let mut rows = Vec::new();
    for episode in episodes {
        let Some(row) = episode_row(episode) else {
            continue;
        };
        let key = episode_key(
            row.series,
            row.episode,
            row.firstaired.as_deref().unwrap_or_default(),
        );
        if known.insert(key, ()).is_none() {
            rows.push(row);
        }
    }
    let mut inserted = 0;
    for chunk in rows.chunks(500) {
        inserted += insert_rows(db, video_id, chunk).await?;
    }
    Ok(inserted)
}

async fn insert_rows(db: &MySqlPool, video_id: i64, rows: &[EpisodeRow]) -> Result<u64> {
    let mut insert: QueryBuilder<MySql> = QueryBuilder::new(
        "INSERT IGNORE INTO tv_episodes (videos_id, series, episode, se_complete, title, firstaired, summary) ",
    );
    insert.push_values(rows, |mut row, episode| {
        row.push_bind(video_id)
            .push_bind(episode.series)
            .push_bind(episode.episode)
            .push_bind(&episode.se_complete)
            .push_bind(&episode.title)
            .push_bind(&episode.firstaired)
            .push_bind(&episode.summary);
    });
    Ok(insert.build().execute(db).await?.rows_affected())
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn airdates() {
        assert_eq!(
            normalize_airdate("2020-03-04").as_deref(),
            Some("2020-03-04")
        );
        assert_eq!(
            normalize_airdate("2020-03-04 12:00:00").as_deref(),
            Some("2020-03-04")
        );
        assert_eq!(normalize_airdate("0000-00-00"), None);
        assert_eq!(normalize_airdate("''"), None);
        assert_eq!(normalize_airdate("soon"), None);
    }

    #[test]
    fn unnumbered_episodes_need_an_airdate() {
        let special = Episode {
            series: 0,
            episode: 0,
            ..Episode::default()
        };
        assert!(episode_row(&special).is_none());
        let dated = Episode {
            firstaired: "2020-01-02".to_owned(),
            ..special
        };
        let row = episode_row(&dated).unwrap();
        assert_eq!(row.se_complete, "S00E00");
        assert_eq!(
            episode_key(row.series, row.episode, "2020-01-02"),
            "0x0@2020-01-02"
        );
        assert_eq!(episode_key(3, 4, ""), "3x4");
    }

    #[test]
    fn country_codes() {
        assert_eq!(country_cased("uk").as_deref(), Some("GB"));
        assert_eq!(country_cased("us").as_deref(), Some("US"));
        assert_eq!(country_cased("").as_deref(), Some(""));
        assert_eq!(country_cased("Canada"), None);
    }

    #[test]
    fn loose_patterns_drop_punctuation() {
        assert_eq!(loose_pattern("Don't Stop!"), "%Dont%Stop%");
    }
}
