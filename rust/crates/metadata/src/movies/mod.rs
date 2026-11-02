//! Finds an IMDb id for movie releases and stores the movie's metadata. Port of `MovieService`.

pub mod commands;

use std::sync::{Arc, LazyLock};
use std::time::Duration;

use anyhow::Result;
use md5::{Digest, Md5};
use nntmux_core::pcre::Pcre;
use nntmux_predb::selector::similar_text_percent;
use nntmux_runtime::Ctx;
use serde::{Deserialize, Serialize};
use serde_json::Value;
use sqlx::{MySql, QueryBuilder, Row};
use tracing::{error, info, warn};

use crate::candidates::{IMDB_PENDING_VALUES, SETTLED, imdb_needs_lookup_sql, release_scope};
use crate::clients::imdb::ImdbTitle;
use crate::clients::tmdb::Tmdb;
use crate::images::Profile;
use crate::php;
use crate::services::Services;
use crate::settings::{LookupMode, MetadataSettings, Renamed};
use crate::tv::check_guid_char;
use crate::tv::providers::is_valid_imdb;

const MATCH_PERCENT: f64 = 75.0;
/// Lower threshold when the year matches exactly; allows matching by an alternate title.
const MATCH_PERCENT_ALT_TITLE: f64 = 55.0;
const YEAR_MATCH_PERCENT: f64 = 80.0;
const MOVIE_ROOT: i64 = 2000;
const MOVIE_OTHER: i64 = 2999;
const KIND: &str = "movies";
const WEEK: Duration = Duration::from_secs(7 * 24 * 3600);
const SIX_HOURS: Duration = Duration::from_secs(6 * 3600);
const THIRTY_DAYS_SECONDS: i64 = 30 * 24 * 3600;

const FOLLOWING: &str = r"[^\w]((1080|480|720|2160)p|AC3D|Directors([^\w]CUT)?|DD5\.1|(DVD|BD|BR|UHD)(Rip)?|BluRay|divx|HDTV|iNTERNAL|LiMiTED|(Real\.)?PROPER|RE(pack|Rip)|Sub\.?(fix|pack)|Unrated|WEB-?DL|WEBRip|(x|H|HEVC)[ ._-]?26[45]|xvid|AAC|REMUX)[^\w]";

fn pcre(pattern: &str) -> Pcre {
    Pcre::new(pattern).expect("a valid regex")
}

static CANDIDATE_ID: LazyLock<Pcre> =
    LazyLock::new(|| pcre(r"/(?:imdb.*?)?(?:tt|Title\?)(?P<imdbid>\d{5,})/i"));
static NAME_YEAR: LazyLock<Pcre> =
    LazyLock::new(|| pcre(r"/(?P<name>[\w. -]+)[^\w](?P<year>(19|20)\d\d)/i"));
static NAME_FOLLOWING: LazyLock<Pcre> =
    LazyLock::new(|| pcre(&format!(r"/([^\w]{{2,}})?(?P<name>[\w .-]+?){FOLLOWING}/i")));
static NAME_START: LazyLock<Pcre> =
    LazyLock::new(|| pcre(&format!(r"/^(?P<name>[\w .-]+?){FOLLOWING}/i")));
static ARCHIVE_NAME: LazyLock<Pcre> = LazyLock::new(|| pcre(r"/\.(rar|zip|avi|mkv|mp4)$/i"));
static STRIP_FOLLOWING: LazyLock<Pcre> = LazyLock::new(|| pcre(&format!("/{FOLLOWING}/i")));
static PARENS: LazyLock<Pcre> = LazyLock::new(|| pcre(r"/\([^)]*\)/i"));
static GROUP_SUFFIX: LazyLock<Pcre> = LazyLock::new(|| pcre(r"/-[A-Z0-9].*$/i"));
static SPACES: LazyLock<Pcre> = LazyLock::new(|| pcre(r"/\s{2,}/"));
static ALL_DIGITS: LazyLock<Pcre> = LazyLock::new(|| pcre(r"/^\d+$/"));
static FOUR_DIGITS: LazyLock<Pcre> = LazyLock::new(|| pcre(r"/(\d{4})/"));
static FULL_SUMMARY: LazyLock<Pcre> = LazyLock::new(|| pcre(r"/\s+See full summary »/u"));
static TYPE_SEPARATORS: LazyLock<Pcre> = LazyLock::new(|| pcre(r"/[._]/"));

fn md5_hex(text: &str) -> String {
    hex::encode(Md5::digest(text.as_bytes()))
}

fn similarity(left: &str, right: &str) -> f64 {
    similar_text_percent(left.as_bytes(), right.as_bytes())
}

/// `parseMovieSearchName`: the title and year in a release name.
pub fn parse_movie_name(release: &str) -> Option<(String, String)> {
    let cleaned = release.replace(['.', '_'], " ");
    let cleaned = php::bytes_to_string(SPACES.replace_all(cleaned.as_bytes(), b" "));
    let cleaned = php::trim(&cleaned);
    if cleaned.is_empty() {
        return None;
    }
    let subject = cleaned.as_bytes();
    let named = |pattern: &Pcre, group: &str| {
        pattern.captures(subject).map(|hits| {
            hits.named()
                .into_iter()
                .find(|(key, _)| *key == group)
                .map(|(_, value)| value.to_vec())
                .unwrap_or_default()
        })
    };
    let mut year = Vec::new();
    let name = if let Some(name) = named(&NAME_YEAR, "name") {
        year = named(&NAME_YEAR, "year").unwrap_or_default();
        name
    } else if let Some(name) = named(&NAME_FOLLOWING, "name") {
        name
    } else if let Some(name) = named(&NAME_START, "name") {
        name
    } else if subject.len() <= 100 && !ARCHIVE_NAME.is_match(subject) {
        subject.to_vec()
    } else {
        return None;
    };
    if name.is_empty() || name == b"0" {
        return None;
    }
    let name = STRIP_FOLLOWING.replace_all(&name, b" ");
    let mut name = PARENS.replace_all(&name, b" ");
    while let Some(open) = name.iter().position(|byte| *byte == b'[') {
        let Some(close) = name[open..].iter().position(|byte| *byte == b']') else {
            break;
        };
        let mut joined = name[..open].to_vec();
        joined.push(b' ');
        joined.extend_from_slice(&name[open + close + 1..]);
        name = joined;
    }
    for byte in &mut name {
        if matches!(*byte, b'.' | b'_') {
            *byte = b' ';
        }
    }
    let name = GROUP_SUFFIX.replace_all(&name, b"");
    let name = SPACES.replace_all(&name, b" ");
    let name = php::bytes_to_string(name);
    let name = php::trim(&name);
    if name.len() > 2 && !ALL_DIGITS.is_match(name.as_bytes()) {
        return Some((name.to_owned(), php::bytes_to_string(year)));
    }
    None
}

/// Metadata from one source, in the shape `updateMovieInfo` merges.
#[derive(Clone, Debug, Default, PartialEq, Serialize, Deserialize)]
pub struct Facts {
    pub title: String,
    pub tmdbid: i64,
    pub traktid: i64,
    pub imdbid: String,
    pub rating: String,
    pub rt_rating: String,
    pub actors: Vec<String>,
    pub director: Vec<String>,
    pub plot: String,
    pub tagline: String,
    pub year: String,
    pub genre: Vec<String>,
    pub cover: String,
    pub backdrop: String,
    pub language: String,
    pub kind: String,
}

impl From<ImdbTitle> for Facts {
    fn from(title: ImdbTitle) -> Self {
        Self {
            title: title.title,
            imdbid: title.imdbid,
            rating: title.rating,
            actors: title.actors,
            director: title.director,
            plot: title.plot,
            year: title.year,
            genre: title.genre,
            cover: title.cover,
            language: title.language,
            kind: title.kind,
            ..Self::default()
        }
    }
}

fn list_or_comma(value: Option<&Value>) -> Vec<String> {
    match value {
        Some(Value::Array(items)) => items.iter().map(|item| php::text(Some(item))).collect(),
        other => {
            let text = php::text(other);
            if php::is_empty(&text) {
                Vec::new()
            } else {
                vec![text]
            }
        }
    }
}

fn first_non_empty(values: [&str; 4]) -> &str {
    values
        .into_iter()
        .find(|value| !php::is_empty(value))
        .unwrap_or_default()
}

fn joined(values: &[String]) -> String {
    let mut seen = std::collections::HashSet::new();
    values
        .iter()
        .filter(|value| seen.insert(value.as_str()))
        .map(String::as_str)
        .collect::<Vec<_>>()
        .join(", ")
}

/// `ucwords`.
fn ucwords(text: &str) -> String {
    let mut output = String::with_capacity(text.len());
    let mut start = true;
    for c in text.chars() {
        output.push(if start { c.to_ascii_uppercase() } else { c });
        start = matches!(c, ' ' | '\t' | '\r' | '\n' | '\x0B' | '\x0C');
    }
    output
}

/// One `update` column. Empty values leave what's stored.
pub struct Column(&'static str, String);

/// `update`: writes the non-empty values, then fetches a cover if none was given.
pub async fn upsert_movie(services: &Services, columns: Vec<Column>) -> Result<bool> {
    let columns: Vec<Column> = columns
        .into_iter()
        .filter(|Column(_, value)| !php::is_empty(value))
        .map(|Column(name, value)| {
            let value = if matches!(name, "genre" | "language") {
                php::mb_substr(&value, 64)
            } else {
                value
            };
            Column(name, value.trim_end_matches([',', ' ']).to_owned())
        })
        .collect();
    if columns.is_empty() {
        return Ok(false);
    }
    let mut upsert: QueryBuilder<MySql> = QueryBuilder::new("INSERT INTO movieinfo (");
    for (index, Column(name, _)) in columns.iter().enumerate() {
        if index > 0 {
            upsert.push(", ");
        }
        upsert.push(*name);
    }
    upsert.push(", created_at, updated_at) VALUES (");
    for (index, Column(_, value)) in columns.iter().enumerate() {
        if index > 0 {
            upsert.push(", ");
        }
        upsert.push_bind(value.clone());
    }
    upsert.push(", NOW(), NOW()) ON DUPLICATE KEY UPDATE created_at = NOW(), updated_at = NOW()");
    for Column(name, value) in &columns {
        upsert
            .push(", ")
            .push(*name)
            .push(" = ")
            .push_bind(value.clone());
    }
    upsert.build().execute(&services.db).await?;
    Ok(true)
}

/// The release being processed, against which fetched metadata is checked.
pub struct MovieLookup {
    services: Arc<Services>,
    settings: MetadataSettings,
    title: String,
    year: String,
    searchname: Option<(i64, String)>,
    /// A fetcher rejected the candidate because its title or year doesn't match.
    candidate_mismatch: bool,
}

/// How `doMovieUpdate` ended.
#[derive(Debug, PartialEq, Eq)]
enum Assigned {
    /// An id was found or was already there.
    Id(String),
    /// Nothing usable in the buffer, or the candidate was rejected.
    No,
}

impl MovieLookup {
    pub fn new(services: Arc<Services>, settings: MetadataSettings) -> Self {
        Self {
            services,
            settings,
            title: String::new(),
            year: String::new(),
            searchname: None,
            candidate_mismatch: false,
        }
    }

    fn year_difference(&self, year: &str) -> Option<i64> {
        if self.year.is_empty() || php::is_empty(year) {
            return None;
        }
        let hits = FOUR_DIGITS.captures(year.as_bytes())?;
        let found: i64 = std::str::from_utf8(hits.group(1)?).ok()?.parse().ok()?;
        Some((self.year.parse::<i64>().unwrap_or(0) - found).abs())
    }

    fn near_exact_title(&self, candidate: &str, percent: f64) -> bool {
        if percent >= 95.0 {
            return true;
        }
        let normalize = |value: &str| -> String {
            value
                .to_ascii_lowercase()
                .chars()
                .filter(char::is_ascii_alphanumeric)
                .collect()
        };
        normalize(candidate) == normalize(&self.title)
    }

    /// `matchesCurrentContext`.
    fn matches_context(&mut self, title: &str, year: &str) -> bool {
        if self.title.is_empty() {
            return true;
        }
        let empty_title = php::is_empty(title);
        let percent = if empty_title {
            0.0
        } else {
            similarity(&self.title, title)
        };
        let near_exact = !empty_title && self.near_exact_title(title, percent);
        let difference = self.year_difference(year);
        // A conflicting year means a different movie, like "Moana 2" (2024) for a "Moana" (2026)
        // release. Near-exact titles get a year's tolerance, since groups mislabel the year.
        if difference.is_some_and(|difference| difference > i64::from(near_exact)) {
            self.candidate_mismatch = true;
            return false;
        }
        // Alternate or international titles are acceptable when the exact year confirms them.
        if percent < MATCH_PERCENT && difference != Some(0) {
            self.candidate_mismatch = true;
            return false;
        }
        true
    }

    async fn stored_movie(&self, imdb: &str) -> Result<Option<(i64, String, String, Option<i64>)>> {
        let row = sqlx::query(
            "SELECT CAST(id AS SIGNED) AS id, title, year, \
             TIMESTAMPDIFF(SECOND, updated_at, NOW()) AS age FROM movieinfo WHERE imdbid = ?",
        )
        .bind(imdb)
        .fetch_optional(&self.services.db)
        .await?;
        row.map(|row| {
            Ok((
                row.try_get("id")?,
                row.try_get("title")?,
                row.try_get("year")?,
                row.try_get("age")?,
            ))
        })
        .transpose()
    }

    async fn candidate_matches_local(&mut self, imdb: &str) -> Result<bool> {
        if self.title.is_empty() {
            return Ok(true);
        }
        let Some((_, title, year, _)) = self.stored_movie(imdb).await? else {
            return Ok(true);
        };
        Ok(self.matches_context(&title, &year))
    }

    /// `verifyReleaseMovieMatch`: `None` when it can't be verified.
    pub async fn verify_match(&mut self, searchname: &str, imdb: &str) -> Result<Option<bool>> {
        let Some((title, year)) = parse_movie_name(searchname) else {
            return Ok(None);
        };
        self.title = title;
        self.year = year;
        Ok(Some(self.candidate_matches_local(imdb).await?))
    }

    async fn has_cover(&self, imdb: &str) -> Result<bool> {
        let cover: Option<i8> = sqlx::query_scalar("SELECT cover FROM movieinfo WHERE imdbid = ?")
            .bind(imdb)
            .fetch_optional(&self.services.db)
            .await?;
        Ok(cover == Some(1) || self.services.images.exists(KIND, &format!("{imdb}-cover")))
    }

    async fn save_asset(&self, name: String, url: &str, profile: Profile) -> bool {
        self.services
            .images
            .save_remote(&name, url, KIND, profile)
            .await
    }

    async fn tmdb_properties(&self, id: &str, by_tmdb_id: bool) -> Option<Facts> {
        let lookup = if !by_tmdb_id && matches!(id.len(), 7 | 8) {
            format!("tt{id}")
        } else {
            id.to_owned()
        };
        let key = format!("tmdb_movie_{}", md5_hex(&lookup));
        if let Some(cached) = self.services.cache.get_entry::<Facts>(&key).await {
            return cached;
        }
        let tmdb = &self.services.tmdb;
        if !tmdb.is_configured() {
            return None;
        }
        let found = match tmdb.movie(&lookup, &["credits"]).await {
            Ok(found) => found,
            Err(failure) => {
                warn!(lookup, error = %failure, "TMDB API error");
                self.services
                    .cache
                    .put_entry::<Facts>(&key, None, SIX_HOURS)
                    .await;
                return None;
            }
        };
        let facts = match found.filter(|found| found.as_object().is_some_and(|o| !o.is_empty())) {
            Some(movie) => self.tmdb_facts(&movie).await,
            None => None,
        };
        self.services
            .cache
            .put_entry(&key, facts.as_ref(), WEEK)
            .await;
        facts
    }

    async fn tmdb_facts(&self, movie: &Value) -> Option<Facts> {
        let title = movie
            .get("title")
            .and_then(Value::as_str)
            .unwrap_or_default();
        if !self.title.is_empty()
            && !php::is_empty(title)
            && similarity(&self.title, title) < MATCH_PERCENT
        {
            let id = php::int(movie.get("id"));
            let alternatives = if id > 0 {
                self.services
                    .tmdb
                    .movie_alternative_titles(id)
                    .await
                    .ok()
                    .flatten()
            } else {
                None
            };
            let matched = alternatives.as_ref().is_some_and(|alternatives| {
                php::items(alternatives.get("titles"))
                    .iter()
                    .any(|alternative| {
                        let title = php::text(alternative.get("title"));
                        !title.is_empty() && similarity(&self.title, &title) >= MATCH_PERCENT
                    })
            });
            if !matched {
                return None;
            }
        }
        let release_date = movie
            .get("release_date")
            .and_then(Value::as_str)
            .unwrap_or_default();
        let release_year = release_date.get(..4).unwrap_or_default();
        if !self.year.is_empty()
            && !php::is_empty(release_date)
            && similarity(&self.year, release_year) < YEAR_MATCH_PERCENT
        {
            return None;
        }
        let names = |list: Option<&Value>| -> Vec<String> {
            php::items(list)
                .iter()
                .map(|member| php::text(member.get("name")))
                .filter(|name| !php::is_empty(name))
                .collect()
        };
        let credits = movie.get("credits").unwrap_or(&Value::Null);
        let director = php::items(credits.get("crew"))
            .iter()
            .find(|member| {
                member.get("department").and_then(Value::as_str) == Some("Directing")
                    && member.get("job").and_then(Value::as_str) == Some("Director")
            })
            .map(|member| php::text(member.get("name")))
            .filter(|name| !name.is_empty());
        let vote = movie
            .get("vote_average")
            .and_then(Value::as_f64)
            .unwrap_or(0.0);
        let image = |key: &str| {
            let path = movie.get(key).and_then(Value::as_str).unwrap_or_default();
            if php::is_empty(path) {
                String::new()
            } else {
                Tmdb::original_image_url(path)
            }
        };
        Some(Facts {
            title: title.to_owned(),
            tmdbid: php::int(movie.get("id")),
            imdbid: php::text(movie.get("imdb_id")).replace("tt", ""),
            rating: if vote > 0.0 {
                vote.to_string()
            } else {
                String::new()
            },
            actors: names(credits.get("cast")),
            director: director.into_iter().collect(),
            plot: movie
                .get("overview")
                .and_then(Value::as_str)
                .unwrap_or_default()
                .to_owned(),
            tagline: movie
                .get("tagline")
                .and_then(Value::as_str)
                .unwrap_or_default()
                .to_owned(),
            year: release_year.to_owned(),
            genre: names(movie.get("genres")),
            cover: image("poster_path"),
            backdrop: image("backdrop_path"),
            ..Facts::default()
        })
    }

    async fn imdb_properties(&mut self, imdb: &str) -> Option<Facts> {
        let key = format!("imdb_movie_{}", md5_hex(imdb));
        if let Some(cached) = self.services.cache.get_entry::<Facts>(&key).await {
            let facts = cached?;
            return self
                .matches_context(&facts.title, &facts.year)
                .then_some(facts);
        }
        let services = self.services.clone();
        if !services.imdb.is_enabled() {
            return None;
        }
        let fetched = services.imdb.fetch_by_id(imdb).await;
        let Some(title) = fetched.title.filter(|title| !php::is_empty(&title.title)) else {
            if fetched.skipped {
                return None;
            }
            let ttl = if fetched.blocked {
                Duration::from_secs(30 * 60)
            } else {
                warn!(imdb, reason = ?fetched.reason, "IMDb metadata fetch failed.");
                SIX_HOURS
            };
            services.cache.put_entry::<Facts>(&key, None, ttl).await;
            return None;
        };
        // Cache regardless of this release: the check is per release and mustn't poison others.
        let facts = Facts::from(title);
        services.cache.put_entry(&key, Some(&facts), WEEK).await;
        self.matches_context(&facts.title, &facts.year)
            .then_some(facts)
    }

    async fn trakt_properties(&mut self, imdb: &str) -> Option<Facts> {
        let services = self.services.clone();
        if !services.trakt.is_configured() {
            return None;
        }
        let key = format!("trakt_movie_{}", md5_hex(imdb));
        if let Some(cached) = services.cache.get_entry::<Facts>(&key).await {
            let facts = cached?;
            return self
                .matches_context(&facts.title, &facts.year)
                .then_some(facts);
        }
        let found = services
            .trakt
            .movie_summary(&format!("tt{imdb}"))
            .await
            .ok()
            .flatten();
        let Some(movie) = found.filter(|movie| !php::is_empty(&php::text(movie.get("title"))))
        else {
            services
                .cache
                .put_entry::<Facts>(&key, None, SIX_HOURS)
                .await;
            return None;
        };
        let facts = Facts {
            title: php::text(movie.get("title")),
            traktid: php::int(movie.get("ids").and_then(|ids| ids.get("trakt"))),
            plot: php::text(movie.get("overview")),
            tagline: php::text(movie.get("tagline")),
            year: php::text(movie.get("year")),
            genre: list_or_comma(movie.get("genres")),
            rating: php::text(movie.get("rating")),
            ..Facts::default()
        };
        services.cache.put_entry(&key, Some(&facts), WEEK).await;
        self.matches_context(&facts.title, &facts.year)
            .then_some(facts)
    }

    async fn omdb_properties(&self, imdb: &str) -> Option<Facts> {
        let services = &self.services;
        if !services.omdb.is_configured() {
            return None;
        }
        let key = format!("omdb_movie_{}", md5_hex(imdb));
        if let Some(cached) = services.cache.get_entry::<Facts>(&key).await {
            return cached;
        }
        let facts = services
            .omdb
            .fetch(&format!("tt{imdb}"))
            .await
            .and_then(|data| self.omdb_facts(&data));
        let ttl = if facts.is_some() { WEEK } else { SIX_HOURS };
        services.cache.put_entry(&key, facts.as_ref(), ttl).await;
        facts
    }

    fn omdb_facts(&self, data: &Value) -> Option<Facts> {
        let response = php::text(data.get("Response"));
        if response.contains("Error:") || response == "False" {
            return None;
        }
        let title = php::text(data.get("Title"));
        let year = php::text(data.get("Year"));
        if !php::is_empty(&self.title) {
            if similarity(&self.title, &title) < MATCH_PERCENT {
                return None;
            }
            if !php::is_empty(&self.year) && similarity(&self.year, &year) < YEAR_MATCH_PERCENT {
                return None;
            }
        }
        let ratings = php::items(data.get("Ratings"));
        let rt_rating = if ratings.len() > 1 {
            php::text(ratings[1].get("Value"))
        } else {
            String::new()
        };
        let single = |key: &str| list_or_comma(data.get(key));
        Some(Facts {
            title,
            cover: php::text(data.get("Poster")),
            genre: single("Genre"),
            year,
            plot: php::text(data.get("Plot")),
            rating: php::text(data.get("imdbRating")),
            rt_rating,
            tagline: php::text(data.get("Tagline")),
            director: single("Director"),
            actors: single("Actors"),
            language: php::text(data.get("Language")),
            ..Facts::default()
        })
    }

    /// `updateMovieInfo`: merges every source and stores the movie.
    async fn update_movie_info(&mut self, imdb: &str) -> Result<bool> {
        let tmdb = self.tmdb_properties(imdb, false).await;
        let scraped = self.imdb_properties(imdb).await;
        let trakt = self.trakt_properties(imdb).await;
        let omdb = self.omdb_properties(imdb).await;
        if scraped.is_none() && tmdb.is_none() && trakt.is_none() && omdb.is_none() {
            return Ok(false);
        }
        let fanart = self.services.fanart.movie_properties(imdb).await;
        let [i, t, k, o] =
            [&scraped, &tmdb, &trakt, &omdb].map(|facts| facts.clone().unwrap_or_default());

        // Fanart.tv over TMDB, TMDB over IMDb, IMDb over OMDb.
        let mut cover = false;
        for url in [
            fanart.as_ref().map_or("", |art| art.cover.as_str()),
            &t.cover,
            &i.cover,
            &o.cover,
        ] {
            if !cover && !php::is_empty(url) {
                cover = self
                    .save_asset(format!("{imdb}-cover"), url, Profile::Original)
                    .await;
                if !cover {
                    warn!(imdb, "couldn't save a movie cover");
                }
            }
        }
        let mut backdrop = false;
        for url in [
            fanart.as_ref().map_or("", |art| art.backdrop.as_str()),
            &t.backdrop,
        ] {
            if !backdrop && !php::is_empty(url) {
                backdrop = self
                    .save_asset(format!("{imdb}-backdrop"), url, Profile::Backdrop)
                    .await;
            }
        }
        if let Some(banner) = fanart.as_ref().and_then(|art| art.banner.as_deref()) {
            self.save_asset(format!("{imdb}-banner"), banner, Profile::Original)
                .await;
        }

        let title = first_non_empty([&i.title, &t.title, &k.title, &o.title]);
        let title = php::entity_decode(title).replace(['/', '\\'], "");
        let rating = php::leading_int(first_non_empty([
            &i.rating, &t.rating, &k.rating, &o.rating,
        ]));
        let plot = first_non_empty([&i.plot, &t.plot, &k.plot, &o.plot]);
        let plot = php::bytes_to_string(FULL_SUMMARY.replace_all(plot.as_bytes(), b" "));
        let genre = [&i.genre, &t.genre, &k.genre, &o.genre]
            .into_iter()
            .find(|genre| !genre.is_empty())
            .map(|genre| joined(genre))
            .unwrap_or_default();
        let people = |lists: [&Vec<String>; 3]| {
            lists
                .into_iter()
                .find(|list| !list.is_empty())
                .map(|list| joined(list))
                .unwrap_or_default()
        };
        let director = people([&i.director, &o.director, &t.director]);
        let actors = people([&i.actors, &o.actors, &t.actors]);
        let language = if php::is_empty(&i.language) {
            &o.language
        } else {
            &i.language
        };
        let kind = php::bytes_to_string(TYPE_SEPARATORS.replace_all(i.kind.as_bytes(), b" "));
        let rt_rating = if php::is_empty(&o.rt_rating) {
            "N/A"
        } else {
            &o.rt_rating
        };
        let flag = |set: bool| if set { "1".to_owned() } else { String::new() };
        let columns = vec![
            Column("actors", php::entity_decode(&actors)),
            Column("backdrop", flag(backdrop)),
            Column("cover", flag(cover)),
            Column("director", php::entity_decode(&director)),
            Column("genre", php::entity_decode(&genre)),
            Column("imdbid", imdb.to_owned()),
            Column("language", php::entity_decode(language)),
            Column("plot", php::entity_decode(&plot)),
            Column("rating", rating.to_string()),
            Column("rtrating", rt_rating.to_owned()),
            Column(
                "tagline",
                php::entity_decode(first_non_empty([
                    &i.tagline, &t.tagline, &k.tagline, &o.tagline,
                ])),
            ),
            Column("title", title),
            Column("tmdbid", t.tmdbid.to_string()),
            Column("traktid", k.traktid.to_string()),
            Column("type", php::entity_decode(&ucwords(&kind))),
            Column(
                "year",
                first_non_empty([&i.year, &t.year, &k.year, &o.year]).to_owned(),
            ),
        ];
        let stored = self.store(columns, cover).await?;
        // The file may have appeared meanwhile through a race.
        if !cover && self.has_cover(imdb).await? {
            self.mark_cover(imdb).await?;
        }
        Ok(stored)
    }

    async fn mark_cover(&self, imdb: &str) -> Result<()> {
        sqlx::query("UPDATE movieinfo SET cover = 1 WHERE imdbid = ?")
            .bind(imdb)
            .execute(&self.services.db)
            .await?;
        Ok(())
    }

    /// `update`, with its follow-up: a movie stored without a cover tries to find one.
    async fn store(&mut self, columns: Vec<Column>, cover_provided: bool) -> Result<bool> {
        let imdb = columns
            .iter()
            .find(|Column(name, _)| *name == "imdbid")
            .map(|Column(_, value)| value.clone())
            .unwrap_or_default();
        if !upsert_movie(&self.services, columns).await? {
            return Ok(false);
        }
        if !php::is_empty(&imdb)
            && !cover_provided
            && !self.has_cover(&imdb).await?
            && self.fetch_cover_only(&imdb).await
        {
            self.mark_cover(&imdb).await?;
        }
        Ok(true)
    }

    async fn fetch_cover_only(&mut self, imdb: &str) -> bool {
        let name = format!("{imdb}-cover");
        if let Some(art) = self.services.fanart.movie_properties(imdb).await
            && self
                .save_asset(name.clone(), &art.cover, Profile::Original)
                .await
        {
            return true;
        }
        if let Some(facts) = self.tmdb_properties(imdb, false).await
            && !php::is_empty(&facts.cover)
            && self
                .save_asset(name.clone(), &facts.cover, Profile::Original)
                .await
        {
            return true;
        }
        if let Some(facts) = self.imdb_properties(imdb).await
            && !php::is_empty(&facts.cover)
            && self
                .save_asset(name.clone(), &facts.cover, Profile::Original)
                .await
        {
            return true;
        }
        if let Some(facts) = self.omdb_properties(imdb).await
            && !php::is_empty(&facts.cover)
            && self.save_asset(name, &facts.cover, Profile::Original).await
        {
            return true;
        }
        false
    }

    /// `parseTraktTv`: stores what a Trakt movie summary carries.
    async fn store_trakt(&mut self, data: &Value) -> Result<()> {
        let ids = data.get("ids").unwrap_or(&Value::Null);
        let imdb = php::text(ids.get("imdb"));
        if php::is_empty(&imdb) {
            return Ok(());
        }
        let imdb = imdb.strip_prefix("tt").unwrap_or(&imdb).to_owned();
        let trailer = php::ireplace_ascii(
            &php::ireplace_ascii(&php::text(data.get("trailer")), "watch?v=", "embed/"),
            "http://",
            "https://",
        );
        let exists = self.services.images.exists(KIND, &format!("{imdb}-cover"));
        let columns = vec![
            Column("genre", list_or_comma(data.get("genres")).join(", ")),
            Column("imdbid", imdb),
            Column("language", php::text(data.get("language"))),
            Column("plot", php::text(data.get("overview"))),
            Column("rating", php::text(data.get("rating"))),
            Column("tagline", php::text(data.get("tagline"))),
            Column("title", php::text(data.get("title"))),
            Column("tmdbid", php::text(ids.get("tmdb"))),
            Column("traktid", php::text(ids.get("trakt"))),
            Column("trailer", trailer),
            Column(
                "cover",
                if exists {
                    "1".to_owned()
                } else {
                    String::new()
                },
            ),
            Column("year", php::text(data.get("year"))),
        ];
        self.store(columns, exists).await?;
        Ok(())
    }

    /// `doMovieUpdate`: validates a candidate id, refreshes its metadata and links the release.
    async fn assign(&mut self, buffer: &str, service: &str, release_id: i64) -> Result<Assigned> {
        self.candidate_mismatch = false;
        let db = self.services.db.clone();
        let existing: Option<Option<String>> =
            sqlx::query_scalar("SELECT imdbid FROM releases WHERE id = ?")
                .bind(release_id)
                .fetch_optional(&db)
                .await?;
        if let Some(Some(existing)) = existing
            && is_valid_imdb(&existing)
        {
            return Ok(Assigned::Id(existing));
        }
        let Some(hits) = CANDIDATE_ID.captures(buffer.as_bytes()) else {
            return Ok(Assigned::No);
        };
        let imdb = hits
            .named()
            .into_iter()
            .find(|(key, _)| *key == "imdbid")
            .map(|(_, value)| php::bytes_to_string(value.to_vec()))
            .unwrap_or_default();
        info!(service, imdb, "found an IMDb id");
        if !self.candidate_matches_local(&imdb).await? {
            info!(
                imdb,
                release_id, "rejected an IMDb id: local title or year mismatch"
            );
            return Ok(Assigned::No);
        }
        let stale = match self.stored_movie(&imdb).await? {
            None => true,
            Some((_, _, _, age)) => age.is_some_and(|age| age > THIRTY_DAYS_SECONDS),
        };
        if stale {
            self.update_movie_info(&imdb).await?;
            // A fetcher recognised the candidate but rejected it, so the id itself is wrong.
            if self.candidate_mismatch {
                info!(
                    imdb,
                    release_id, "rejected an IMDb id: metadata title or year mismatch"
                );
                return Ok(Assigned::No);
            }
        }
        let movie_id = self.stored_movie(&imdb).await?.map(|(id, _, _, _)| id);
        // Only for the title it was matched on: an upgrade may have renamed it meanwhile.
        let mut update: QueryBuilder<MySql> = QueryBuilder::new("UPDATE releases SET imdbid = ");
        update.push_bind(imdb.clone());
        update.push(", movieinfo_id = ").push_bind(movie_id);
        update.push(" WHERE id = ").push_bind(release_id);
        if let Some((id, searchname)) = &self.searchname
            && *id == release_id
        {
            update
                .push(" AND searchname = ")
                .push_bind(searchname.clone());
        }
        if update.build().execute(&db).await?.rows_affected() > 0 {
            self.services.sync_release(release_id).await;
        }
        Ok(Assigned::Id(imdb))
    }

    async fn assigned(&mut self, buffer: &str, service: &str, release_id: i64) -> bool {
        match self.assign(buffer, service, release_id).await {
            Ok(Assigned::Id(_)) => true,
            Ok(Assigned::No) => false,
            Err(failure) => {
                error!(
                    release_id,
                    error = format!("{failure:#}"),
                    "error updating movie information"
                );
                false
            }
        }
    }

    async fn local_search(&mut self) -> Result<Option<String>> {
        if php::is_empty(&self.title) {
            return Ok(None);
        }
        let key = format!(
            "local_imdb_{}",
            md5_hex(&format!("{}{}", self.title, self.year))
        );
        if let Some(cached) = self.services.cache.get_entry::<String>(&key).await {
            return Ok(cached);
        }
        let mut query: QueryBuilder<MySql> =
            QueryBuilder::new("SELECT imdbid, title, year FROM movieinfo WHERE title LIKE ");
        query.push_bind(format!("%{}%", self.title));
        if let Ok(year) = self.year.parse::<i64>() {
            query
                .push(" AND year BETWEEN ")
                .push_bind(year - 2)
                .push(" AND ")
                .push_bind(year + 2);
        }
        let rows = query.build().fetch_all(&self.services.db).await?;
        let mut best: Option<(String, f64)> = None;
        for row in rows {
            let title: String = row.try_get("title")?;
            let year: String = row.try_get("year")?;
            let percent = similarity(&self.title, &title);
            if percent < MATCH_PERCENT {
                continue;
            }
            // A conflicting year rejects sequels and remakes; near-exact titles get a year's slack.
            let tolerance = i64::from(self.near_exact_title(&title, percent));
            if self
                .year_difference(&year)
                .is_some_and(|difference| difference > tolerance)
            {
                continue;
            }
            if best.as_ref().is_none_or(|(_, top)| percent > *top) {
                best = Some((row.try_get("imdbid")?, percent));
            }
        }
        let found = best.map(|(imdb, _)| imdb);
        let ttl = if found.is_some() { WEEK } else { SIX_HOURS };
        self.services
            .cache
            .put_entry(&key, found.as_ref(), ttl)
            .await;
        Ok(found)
    }

    async fn search_local(&mut self, release_id: i64) -> Result<bool> {
        let Some(imdb) = self.local_search().await? else {
            return Ok(false);
        };
        Ok(self
            .assigned(&format!("tt{imdb}"), "Local DB", release_id)
            .await)
    }

    async fn search_imdb(&mut self, release_id: i64) -> bool {
        let services = self.services.clone();
        if !services.imdb.scraping {
            return false;
        }
        for found in services.imdb.search(&self.title).await {
            let percent = similarity(&found.title, &self.title);
            let year_matches = self.year.is_empty()
                || php::is_empty(&found.year)
                || similarity(&self.year, &found.year) >= YEAR_MATCH_PERCENT;
            let threshold = if year_matches {
                MATCH_PERCENT_ALT_TITLE
            } else {
                MATCH_PERCENT
            };
            if percent < threshold || !year_matches {
                continue;
            }
            if self
                .assigned(&format!("tt{}", found.imdbid), "IMDb(scrape)", release_id)
                .await
            {
                return true;
            }
        }
        false
    }

    async fn search_omdb(&mut self, release_id: i64) -> bool {
        let services = self.services.clone();
        if !services.omdb.is_configured() {
            return false;
        }
        let title = self.title.replace(' ', "_").to_lowercase();
        let first_id = |buffer: &Value| {
            php::text(
                php::items(buffer.get("Search"))
                    .first()
                    .and_then(|first| first.get("imdbID")),
            )
        };
        let usable = |buffer: &Option<Value>| {
            buffer.as_ref().is_some_and(|buffer| {
                php::text(buffer.get("Response")) == "True" && !php::is_empty(&first_id(buffer))
            })
        };
        let mut buffer = if self.year.is_empty() {
            services.omdb.search(&title, None).await
        } else {
            services.omdb.search(&title, Some(&self.year)).await
        };
        if !self.year.is_empty() && !usable(&buffer) {
            buffer = services.omdb.search(&title, None).await;
        }
        if !usable(&buffer) {
            return false;
        }
        let Some(buffer) = buffer else {
            return false;
        };
        let first = php::items(buffer.get("Search"))
            .first()
            .cloned()
            .unwrap_or(Value::Null);
        if !self.matches_context(
            &php::text(first.get("Title")),
            &php::text(first.get("Year")),
        ) {
            return false;
        }
        self.assigned(&first_id(&buffer), "OMDbAPI", release_id)
            .await
    }

    async fn search_trakt(&mut self, release_id: i64, movie_name: &str) -> bool {
        let services = self.services.clone();
        if !services.trakt.is_configured() {
            return false;
        }
        let found = services
            .trakt
            .movie_summary(movie_name)
            .await
            .ok()
            .flatten();
        let Some(data) = found else {
            return false;
        };
        let imdb = php::text(data.get("ids").and_then(|ids| ids.get("imdb")));
        if php::is_empty(&imdb)
            || !self.matches_context(&php::text(data.get("title")), &php::text(data.get("year")))
        {
            return false;
        }
        if let Err(failure) = self.store_trakt(&data).await {
            error!(error = format!("{failure:#}"), "Trakt.tv error");
            return false;
        }
        self.assigned(&imdb, "Trakt", release_id).await
    }

    async fn search_tmdb(&mut self, release_id: i64) -> bool {
        let services = self.services.clone();
        if !services.tmdb.is_configured() {
            return false;
        }
        let year = (!self.year.is_empty()).then_some(self.year.clone());
        let Ok(Some(data)) = services
            .tmdb
            .search_movies(&self.title, year.as_deref())
            .await
        else {
            return false;
        };
        if php::int(data.get("total_results")) == 0 {
            return false;
        }
        for result in php::items(data.get("results")) {
            let id = php::int(result.get("id"));
            let date = result
                .get("release_date")
                .and_then(Value::as_str)
                .unwrap_or_default();
            if id == 0 || php::is_empty(date) {
                continue;
            }
            if similarity(&self.year, date.get(..4).unwrap_or_default()) < YEAR_MATCH_PERCENT {
                continue;
            }
            let Some(facts) = self.tmdb_properties(&id.to_string(), true).await else {
                continue;
            };
            if php::is_empty(&facts.imdbid) {
                continue;
            }
            if self
                .assigned(&format!("tt{}", facts.imdbid), "TMDB", release_id)
                .await
            {
                return true;
            }
        }
        false
    }

    /// `processMovieReleases`.
    pub async fn process(
        &mut self,
        group_id: i64,
        guid_char: &str,
        mode: LookupMode,
    ) -> Result<()> {
        if !mode.enabled() {
            return Ok(());
        }
        let db = self.services.db.clone();
        let statement = format!(
            "SELECT CAST(id AS SIGNED) AS id, searchname FROM releases \
             WHERE categories_id BETWEEN {MOVIE_ROOT} AND {MOVIE_OTHER} AND {} AND {SETTLED}{}{} \
             ORDER BY id DESC LIMIT {}",
            imdb_needs_lookup_sql("imdbid"),
            release_scope(group_id, guid_char),
            mode.renamed_filter(),
            self.settings.max_movies_processed.max(0),
        );
        let rows = sqlx::query(sqlx::AssertSqlSafe(statement))
            .fetch_all(&db)
            .await?;
        let mut failed: Vec<(i64, String)> = Vec::new();
        for row in rows {
            let id: i64 = row.try_get("id")?;
            let searchname: String = row.try_get("searchname")?;
            let Some((title, year)) = parse_movie_name(&searchname) else {
                failed.push((id, searchname));
                continue;
            };
            self.title = title;
            self.year = year;
            self.searchname = Some((id, searchname.clone()));
            let movie_name = if self.year.is_empty() {
                self.title.clone()
            } else {
                format!("{} ({})", self.title, self.year)
            };
            let found = self.search_local(id).await?
                || self.search_imdb(id).await
                || self.search_omdb(id).await
                || self.search_trakt(id, &movie_name).await
                || self.search_tmdb(id).await;
            if found {
                continue;
            }
            let pending: Vec<String> = IMDB_PENDING_VALUES
                .iter()
                .map(|v| format!("'{v}'"))
                .collect();
            let statement = format!(
                "SELECT 1 FROM releases WHERE id = {id} AND imdbid IS NOT NULL AND imdbid <> '' \
                 AND imdbid NOT IN ({})",
                pending.join(",")
            );
            let has_id: Option<i32> = sqlx::query_scalar(sqlx::AssertSqlSafe(statement))
                .fetch_optional(&db)
                .await?;
            if has_id.is_none() {
                failed.push((id, searchname));
            }
        }
        if !failed.is_empty() {
            info!(count = failed.len(), "no IMDb id found for some releases");
        }
        for (id, searchname) in &failed {
            // Only for the title it was matched on: an upgrade may have renamed it meanwhile.
            sqlx::query("UPDATE releases SET imdbid = '' WHERE id = ? AND searchname = ?")
                .bind(id)
                .bind(searchname)
                .execute(&db)
                .await?;
        }
        let ids: Vec<i64> = failed.iter().map(|(id, _)| *id).collect();
        self.services.sync_releases(&ids).await;
        Ok(())
    }
}

/// [`crate::process_movies`] starts here.
pub async fn run(ctx: &Ctx, group_id: i64, guid_char: &str, renamed: Renamed) -> Result<()> {
    check_guid_char(guid_char)?;
    let services = Services::shared(ctx)?;
    let settings = MetadataSettings::load(&services.db).await?;
    let mode = renamed.resolve(settings.movie_lookup);
    MovieLookup::new(services, settings)
        .process(group_id, guid_char, mode)
        .await
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn ucwords_and_joined() {
        assert_eq!(ucwords("tv movie"), "Tv Movie");
        assert_eq!(
            joined(&["A".to_owned(), "B".to_owned(), "A".to_owned()]),
            "A, B"
        );
    }

    #[test]
    fn first_non_empty_skips_zero() {
        assert_eq!(first_non_empty(["", "0", "x", "y"]), "x");
        assert_eq!(first_non_empty(["", "", "", ""]), "");
    }
}
