//! The release page, its comments, and the DNZB failure route.

use anyhow::Result;
use askama::Template;
use axum::Router;
use axum::extract::{Path, State};
use axum::http::{HeaderMap, HeaderValue, Method, StatusCode, header};
use axum::middleware::from_fn;
use axum::response::{Html, IntoResponse, Redirect, Response};
use axum::routing::{any, get};
use chrono::{DateTime, NaiveDateTime, Utc};
use nntmux_runtime::Ctx;
use nntmux_search::{Phrases, SearchClient, SearchConfig};
use nntmux_webkit::config::{self, encode_component};
use nntmux_webkit::{
    ClientIp, CurrentUser, Input, Layout, MaybeUser, Session, User, WebResult, guard, throttle,
};
use serde_json::Value;
use sqlx::{MySqlPool, Row};

use crate::auth::back;
use crate::profile::relative;
use crate::releases::{cover_url, local_time, path_segment};

pub fn router() -> Router<Ctx> {
    Router::new()
        .route("/details/{guid}", get(show).post(add_comment))
        .route_layer(from_fn(guard::two_factor))
        .route_layer(from_fn(guard::verified))
        .route_layer(from_fn(guard::authenticated))
}

/// `/failed` authenticates by API token or a session, so it has its own guards.
pub fn failed_router() -> Router<Ctx> {
    Router::new()
        .route("/failed", any(failed))
        .route_layer(from_fn(|request, next| {
            throttle::limit(request, next, "failed", 60, 60)
        }))
}

struct ReleaseView {
    id: i64,
    guid: String,
    searchname: String,
    category: String,
    group_name: String,
    fromname: String,
    totalpart: i64,
    grabs: i64,
    nfostatus: i64,
    passwordstatus: i64,
    imdbid: String,
    articles: Option<(i64, i64)>,
    files: Option<(i64, i64)>,
}

struct ReportView {
    reason: String,
    status: String,
    created: String,
    description: String,
}

struct ResponseView {
    text: String,
    when: String,
    by: String,
}

struct CommentView {
    username: String,
    when: String,
    text: String,
}

impl CommentView {
    fn initial(&self) -> String {
        self.username
            .chars()
            .take(1)
            .flat_map(char::to_uppercase)
            .collect()
    }
}

struct FieldLink {
    label: String,
    href: String,
    external: bool,
}

struct Field {
    label: &'static str,
    value: String,
    links: Vec<FieldLink>,
    wide: bool,
}

struct Panel {
    title: &'static str,
    icon: &'static str,
    fields: Vec<Field>,
    trailer: Option<String>,
}

/// One column's text from a row, whatever its type: `CAST` in the query keeps these uniform.
struct Info {
    columns: Vec<(String, String)>,
}

impl Info {
    fn get(&self, key: &str) -> &str {
        self.columns
            .iter()
            .find(|(name, _)| name == key)
            .map_or("", |(_, value)| value.as_str())
    }
}

/// Fetches named columns as text. `columns` and `from` are literals from this file.
async fn fetch_info(
    db: &MySqlPool,
    columns: &[&str],
    from: &str,
    id: &str,
) -> Result<Option<Info>> {
    let select: Vec<String> = columns
        .iter()
        .map(|column| format!("CAST(`{column}` AS CHAR)"))
        .collect();
    let sql = format!("SELECT {} FROM {from} LIMIT 1", select.join(", "));
    let row = sqlx::query(sqlx::AssertSqlSafe(sql))
        .bind(id)
        .fetch_optional(db)
        .await?;
    Ok(row.map(|row| Info {
        columns: columns
            .iter()
            .enumerate()
            .map(|(index, name)| {
                (
                    (*name).to_owned(),
                    row.try_get::<Option<String>, _>(index)
                        .ok()
                        .flatten()
                        .unwrap_or_default(),
                )
            })
            .collect(),
    }))
}

fn text_field(label: &'static str, value: &str, wide: bool) -> Option<Field> {
    (!value.is_empty()).then(|| Field {
        label,
        value: value.to_owned(),
        links: Vec::new(),
        wide,
    })
}

/// `makeFieldLinks`: the first eight comma-separated names, each a search on its cover page.
fn field_links(label: &'static str, value: &str, kind: &str, key: &str) -> Option<Field> {
    let links: Vec<FieldLink> = value
        .split(", ")
        .map(str::trim)
        .filter(|name| !name.is_empty())
        .take(8)
        .map(|name| FieldLink {
            label: name.to_owned(),
            href: format!("/{kind}?{key}={}", encode_component(name)),
            external: false,
        })
        .collect();
    (!links.is_empty()).then_some(Field {
        label,
        value: String::new(),
        links,
        wide: false,
    })
}

fn panel(title: &'static str, icon: &'static str, fields: Vec<Option<Field>>) -> Panel {
    Panel {
        title,
        icon,
        fields: fields.into_iter().flatten().collect(),
        trailer: None,
    }
}

/// `$showData` and friends: only these schemes reach an iframe.
fn safe_trailer(url: &str) -> Option<String> {
    (url.starts_with("https://") || url.starts_with("http://")).then(|| url.to_owned())
}

async fn genre_name(db: &MySqlPool, id: &str) -> Result<String> {
    if id.is_empty() {
        return Ok(String::new());
    }
    let title: Option<String> = sqlx::query_scalar("SELECT title FROM genres WHERE id = ?")
        .bind(id)
        .fetch_optional(db)
        .await?;
    Ok(title.unwrap_or_default())
}

fn date_part(value: &str) -> &str {
    value.split(' ').next().unwrap_or(value)
}

/// `(movie, show, music, game, console, book, anime)`, the panels this release has data for.
async fn load_panels(
    ctx: &Ctx,
    ids: &ReleaseIds,
    trailers: bool,
    dereferrer: &str,
) -> Result<Vec<Panel>> {
    let db = &ctx.db;
    let mut panels = Vec::new();
    if let Some(info) = fetch_info(
        db,
        &[
            "title", "year", "tagline", "rating", "plot", "genre", "director", "actors",
            "language", "trailer",
        ],
        "movieinfo WHERE imdbid = ?",
        &ids.imdbid,
    )
    .await?
    .filter(|_| valid_imdb(&ids.imdbid))
    {
        let title = info.get("title").replace(['/', '\\'], "");
        let mut movie = panel(
            "Movie Information",
            "fa-film",
            vec![
                text_field("Title", &title, false),
                text_field("Year", info.get("year"), false),
                text_field("Tagline", info.get("tagline"), true),
                text_field("IMDB Rating", info.get("rating"), false),
                text_field("Plot Synopsis", info.get("plot"), true),
                field_links("Genre", info.get("genre"), "Movies", "genre"),
                field_links("Director", info.get("director"), "Movies", "director"),
                field_links("Cast", info.get("actors"), "Movies", "actors"),
                text_field("Language", info.get("language"), false),
            ],
        );
        if trailers {
            movie.trailer = safe_trailer(info.get("trailer"));
        }
        panels.push(movie);
    }
    if ids.videos_id > 0
        && let Some(info) = fetch_info(
            db,
            &["title", "started", "tvdb"],
            "videos WHERE id = ?",
            &ids.videos_id.to_string(),
        )
        .await?
    {
        let tvdb = info.get("tvdb");
        let link = (!tvdb.is_empty() && tvdb != "0").then(|| Field {
            label: "TVDB",
            value: String::new(),
            links: vec![FieldLink {
                label: "View on TVDB".to_owned(),
                href: format!("{dereferrer}https://thetvdb.com/?tab=series&id={tvdb}"),
                external: true,
            }],
            wide: false,
        });
        panels.push(panel(
            "TV Show Information",
            "fa-tv",
            vec![
                text_field("Show Title", info.get("title"), false),
                text_field("Started", info.get("started"), false),
                link,
            ],
        ));
    }
    if ids.musicinfo_id > 0
        && let Some(info) = fetch_info(
            db,
            &["title", "artist", "publisher", "releasedate", "genres_id"],
            "musicinfo WHERE id = ?",
            &ids.musicinfo_id.to_string(),
        )
        .await?
    {
        let genre = genre_name(db, info.get("genres_id")).await?;
        panels.push(panel(
            "Music Information",
            "fa-music",
            vec![
                text_field("Album", info.get("title"), false),
                text_field("Artist", info.get("artist"), false),
                text_field("Publisher", info.get("publisher"), false),
                text_field("Release Date", date_part(info.get("releasedate")), false),
                text_field("Genres", &genre, false),
            ],
        ));
    }
    if ids.gamesinfo_id > 0
        && let Some(info) = fetch_info(
            db,
            &["title", "publisher", "releasedate", "genres_id"],
            "gamesinfo WHERE id = ?",
            &ids.gamesinfo_id.to_string(),
        )
        .await?
    {
        let genre = genre_name(db, info.get("genres_id")).await?;
        panels.push(panel(
            "Game Information",
            "fa-gamepad",
            vec![
                text_field("Title", info.get("title"), false),
                text_field("Publisher", info.get("publisher"), false),
                text_field("Release Date", date_part(info.get("releasedate")), false),
                text_field("Genres", &genre, false),
            ],
        ));
    }
    if ids.consoleinfo_id > 0
        && let Some(info) = fetch_info(
            db,
            &["title", "publisher", "releasedate"],
            "consoleinfo WHERE id = ?",
            &ids.consoleinfo_id.to_string(),
        )
        .await?
    {
        panels.push(panel(
            "Console Information",
            "fa-gamepad",
            vec![
                text_field("Title", info.get("title"), false),
                text_field("Publisher", info.get("publisher"), false),
                text_field("Release Date", date_part(info.get("releasedate")), false),
            ],
        ));
    }
    if ids.bookinfo_id > 0
        && let Some(info) = fetch_info(
            db,
            &["title", "author", "publisher", "publishdate", "overview"],
            "bookinfo WHERE id = ?",
            &ids.bookinfo_id.to_string(),
        )
        .await?
    {
        panels.push(panel(
            "Book Information",
            "fa-book",
            vec![
                text_field("Title", info.get("title"), false),
                text_field("Author", info.get("author"), false),
                text_field("Publisher", info.get("publisher"), false),
                text_field("Published", date_part(info.get("publishdate")), false),
                text_field("Overview", info.get("overview"), true),
            ],
        ));
    }
    if ids.anidbid > 0
        && let Some(info) = fetch_info(
            db,
            &[
                "media_type",
                "type",
                "country",
                "status",
                "source",
                "episodes",
                "duration",
                "startdate",
                "enddate",
                "rating",
                "categories",
                "creators",
                "description",
            ],
            "anidb_info WHERE anidbid = ?",
            &ids.anidbid.to_string(),
        )
        .await?
    {
        let titles: Vec<(String, String)> =
            sqlx::query_as("SELECT lang, title FROM anidb_titles WHERE anidbid = ?")
                .bind(ids.anidbid)
                .fetch_all(db)
                .await?;
        let title = |lang: &str| {
            titles
                .iter()
                .find(|(found, _)| found == lang)
                .map_or("", |(_, title)| title.as_str())
                .to_owned()
        };
        let country_code = info.get("country");
        let country: Option<String> = if country_code.is_empty() {
            None
        } else {
            sqlx::query_scalar("SELECT name FROM countries WHERE iso_3166_2 = ?")
                .bind(country_code)
                .fetch_optional(db)
                .await
                .unwrap_or(None)
        };
        let duration = info.get("duration");
        panels.push(panel(
            "Anime Information",
            "fa-dragon",
            vec![
                text_field("Title", &title("en"), false),
                text_field("Romaji Title", &title("x-jat"), false),
                text_field("Original Title", &title("ja"), false),
                text_field("Media Type", info.get("media_type"), false),
                text_field("Format", info.get("type"), false),
                text_field("Country", country.as_deref().unwrap_or(country_code), false),
                text_field("Status", info.get("status"), false),
                text_field("Source", info.get("source"), false),
                text_field("Episodes", info.get("episodes"), false),
                (!duration.is_empty() && duration != "0").then(|| Field {
                    label: "Duration",
                    value: format!("{duration} min"),
                    links: Vec::new(),
                    wide: false,
                }),
                text_field("Start Date", info.get("startdate"), false),
                text_field("End Date", info.get("enddate"), false),
                text_field("Rating", info.get("rating"), false),
                text_field("Genres", info.get("categories"), true),
                text_field("Studios", info.get("creators"), true),
                text_field("Description", info.get("description"), true),
            ],
        ));
    }
    Ok(panels)
}

fn valid_imdb(imdbid: &str) -> bool {
    let digits: String = imdbid.chars().filter(char::is_ascii_digit).collect();
    !digits.is_empty() && digits.parse::<u64>() != Ok(0)
}

struct MediaField {
    label: &'static str,
    value: String,
}

struct MediaPanel {
    title: String,
    icon: &'static str,
    fields: Vec<MediaField>,
}

fn media_fields(pairs: Vec<(&'static str, String)>) -> Vec<MediaField> {
    pairs
        .into_iter()
        .filter(|(_, value)| !value.is_empty() && value != "0")
        .map(|(label, value)| MediaField { label, value })
        .collect()
}

async fn load_media(db: &MySqlPool, release: i64) -> Result<Vec<MediaPanel>> {
    let mut panels = Vec::new();
    let id = release.to_string();
    if let Some(video) = fetch_info(
        db,
        &[
            "containerformat",
            "videocodec",
            "videoformat",
            "videowidth",
            "videoheight",
            "videoaspect",
            "videoframerate",
            "videoduration",
            "overallbitrate",
            "videolibrary",
        ],
        "video_data WHERE releases_id = ?",
        &id,
    )
    .await?
    {
        let resolution = if video.get("videowidth").is_empty()
            || video.get("videoheight").is_empty()
            || video.get("videowidth") == "0"
        {
            String::new()
        } else {
            format!("{}x{}", video.get("videowidth"), video.get("videoheight"))
        };
        let minutes = video.get("videoduration").parse::<f64>().unwrap_or(0.0) / 60_000.0;
        let fields = media_fields(vec![
            ("Container", video.get("containerformat").to_owned()),
            ("Codec", video.get("videocodec").to_owned()),
            ("Format", video.get("videoformat").to_owned()),
            ("Resolution", resolution),
            ("Aspect Ratio", video.get("videoaspect").to_owned()),
            ("Frame Rate", video.get("videoframerate").to_owned()),
            (
                "Duration",
                if minutes >= 0.5 {
                    format!("{} min", minutes.round() as i64)
                } else {
                    String::new()
                },
            ),
            ("Bitrate", video.get("overallbitrate").to_owned()),
            ("Library", video.get("videolibrary").to_owned()),
        ]);
        panels.push(MediaPanel {
            title: "Video".to_owned(),
            icon: "fa-video",
            fields,
        });
    }
    type Audio = (
        Option<String>,
        Option<String>,
        Option<String>,
        Option<String>,
        Option<String>,
        Option<String>,
    );
    let audio: Vec<Audio> = sqlx::query_as(
        "SELECT audioformat, audiomode, audiochannels, audiobitrate, audiolanguage, audiosamplerate FROM audio_data WHERE releases_id = ? ORDER BY audioid",
    )
    .bind(release)
    .fetch_all(db)
    .await?;
    let many = audio.len() > 1;
    for (index, (format, mode, channels, bitrate, language, rate)) in audio.into_iter().enumerate()
    {
        let fields = media_fields(vec![
            ("Format", format.unwrap_or_default()),
            ("Mode", mode.unwrap_or_default()),
            ("Channels", channels.unwrap_or_default()),
            ("Bitrate", bitrate.unwrap_or_default()),
            ("Language", language.unwrap_or_default()),
            ("Sample Rate", rate.unwrap_or_default()),
        ]);
        let title = if many {
            format!("Audio track {}", index + 1)
        } else {
            "Audio".to_owned()
        };
        panels.push(MediaPanel {
            title,
            icon: "fa-volume-up",
            fields,
        });
    }
    let subs: Option<String> = sqlx::query_scalar("SELECT GROUP_CONCAT(subslanguage SEPARATOR ', ') FROM release_subtitles WHERE releases_id = ?")
        .bind(release)
        .fetch_one(db)
        .await?;
    if let Some(subs) = subs.filter(|subs| !subs.is_empty()) {
        panels.push(MediaPanel {
            title: "Subtitles".to_owned(),
            icon: "fa-closed-captioning",
            fields: vec![MediaField {
                label: "Languages",
                value: subs,
            }],
        });
    }
    Ok(panels)
}

struct ReleaseIds {
    imdbid: String,
    videos_id: i64,
    musicinfo_id: i64,
    gamesinfo_id: i64,
    consoleinfo_id: i64,
    bookinfo_id: i64,
    anidbid: i64,
}

#[derive(Template)]
#[template(path = "details/index.html")]
struct DetailsPage {
    layout: Layout,
    release: ReleaseView,
    cover: String,
    size_gb: String,
    added: String,
    posted: String,
    poster_search_url: String,
    failed: i64,
    all_reasons: String,
    reports: Vec<ReportView>,
    responses: Vec<ResponseView>,
    can_edit: bool,
    has_preview: bool,
    has_sample: bool,
    preview_url: String,
    sample_url: String,
    panels: Vec<Panel>,
    media: Vec<MediaPanel>,
    fingerprint_metadata: Option<String>,
    fingerprint_status: String,
    fingerprint_collected: String,
    fingerprint_error: String,
    comments: Vec<CommentView>,
}

fn uppercase_first(text: &str) -> String {
    let mut chars = text.chars();
    chars
        .next()
        .map(|first| first.to_uppercase().chain(chars).collect())
        .unwrap_or_default()
}

fn reason_label(key: &str) -> String {
    match key {
        "duplicate" => "Duplicate Release",
        "fake" => "Fake/Malicious Content",
        "password" => "Password Protected",
        "incomplete" => "Incomplete/Corrupted",
        "wrong_category" => "Wrong Category",
        "spam" => "Spam/Advertisement",
        "other" => "Other",
        other => other,
    }
    .to_owned()
}

type ReportRow = (String, String, Option<DateTime<Utc>>, Option<String>);
type FingerprintRow = (
    String,
    Option<String>,
    Option<String>,
    Option<NaiveDateTime>,
);

async fn show(
    State(ctx): State<Ctx>,
    layout: Layout,
    Path(guid): Path<String>,
    headers: HeaderMap,
    CurrentUser(signed_in): CurrentUser,
) -> WebResult {
    let row = sqlx::query(
        "SELECT CAST(r.id AS SIGNED) AS id, r.searchname, r.fromname, r.imdbid, CONCAT(COALESCE(cp.title, ''), ' > ', COALESCE(c.title, '')) AS category,
                g.name AS group_name, CAST(COALESCE(r.totalpart, 0) AS SIGNED) AS totalpart, CAST(r.grabs AS SIGNED) AS grabs,
                CAST(r.nfostatus AS SIGNED) AS nfostatus, CAST(r.passwordstatus AS SIGNED) AS passwordstatus,
                CAST(r.haspreview AS SIGNED) AS haspreview, CAST(r.jpgstatus AS SIGNED) AS jpgstatus,
                CAST(r.size AS SIGNED) AS size, r.adddate, r.postdate, CAST(r.videos_id AS SIGNED) AS videos_id,
                CAST(COALESCE(r.musicinfo_id, 0) AS SIGNED) AS musicinfo_id, CAST(COALESCE(r.gamesinfo_id, 0) AS SIGNED) AS gamesinfo_id,
                CAST(COALESCE(r.consoleinfo_id, 0) AS SIGNED) AS consoleinfo_id, CAST(COALESCE(r.bookinfo_id, 0) AS SIGNED) AS bookinfo_id,
                CAST(COALESCE(r.anidbid, 0) AS SIGNED) AS anidbid,
                CAST(r.articles_found AS SIGNED) AS articles_found, CAST(r.articles_expected AS SIGNED) AS articles_expected,
                CAST(r.files_found AS SIGNED) AS files_found, CAST(r.files_expected AS SIGNED) AS files_expected
         FROM releases r
         LEFT JOIN categories c ON c.id = r.categories_id
         LEFT JOIN root_categories cp ON cp.id = c.root_categories_id
         LEFT JOIN usenet_groups g ON g.id = r.groups_id
         WHERE r.guid = ? LIMIT 1",
    )
    .bind(&guid)
    .fetch_optional(&ctx.db)
    .await?;
    let Some(row) = row else {
        return Ok(back(&headers, "/"));
    };
    let id: i64 = row.try_get("id")?;
    let imdbid: String = row
        .try_get::<Option<String>, _>("imdbid")?
        .unwrap_or_default();
    let ids = ReleaseIds {
        imdbid: imdbid.clone(),
        videos_id: row.try_get("videos_id")?,
        musicinfo_id: row.try_get("musicinfo_id")?,
        gamesinfo_id: row.try_get("gamesinfo_id")?,
        consoleinfo_id: row.try_get("consoleinfo_id")?,
        bookinfo_id: row.try_get("bookinfo_id")?,
        anidbid: row.try_get("anidbid")?,
    };
    let timezone = signed_in.user.timezone.as_deref();
    let date = |column: &str| -> Result<String> {
        Ok(row
            .try_get::<Option<NaiveDateTime>, _>(column)?
            .map(|at| local_time(at, timezone, "%b %d, %Y %H:%M"))
            .unwrap_or_default())
    };
    let fromname: String = row
        .try_get::<Option<String>, _>("fromname")?
        .unwrap_or_default();
    let articles_expected: Option<i64> = row.try_get("articles_expected")?;
    let files_expected: Option<i64> = row.try_get("files_expected")?;
    let files_found: i64 = row.try_get::<Option<i64>, _>("files_found")?.unwrap_or(0);
    let release = ReleaseView {
        id,
        guid: guid.clone(),
        searchname: row.try_get("searchname")?,
        category: row.try_get("category")?,
        group_name: row
            .try_get::<Option<String>, _>("group_name")?
            .unwrap_or_else(|| "Unknown".to_owned()),
        fromname: fromname.clone(),
        totalpart: row.try_get("totalpart")?,
        grabs: row.try_get("grabs")?,
        nfostatus: row.try_get("nfostatus")?,
        passwordstatus: row.try_get("passwordstatus")?,
        imdbid: if valid_imdb(&imdbid) {
            imdbid.clone()
        } else {
            String::new()
        },
        articles: articles_expected.map(|expected| {
            (
                row.try_get::<Option<i64>, _>("articles_found")
                    .ok()
                    .flatten()
                    .unwrap_or(0),
                expected,
            )
        }),
        files: files_expected
            .filter(|expected| files_found < *expected)
            .map(|expected| (files_found, expected)),
    };
    let size: i64 = row.try_get("size")?;
    let added = date("adddate")?;
    let posted = date("postdate")?;
    let has_preview = row.try_get::<i64, _>("haspreview")? == 1;
    let has_sample = row.try_get::<i64, _>("jpgstatus")? == 1;
    let now = Utc::now();

    let cover = if valid_imdb(&imdbid) {
        cover_url("movies", &format!("{imdbid}-cover"))
    } else {
        None
    }
    .or_else(|| {
        (ids.videos_id > 0)
            .then(|| cover_url("tvshows", &ids.videos_id.to_string()))
            .flatten()
    })
    .or_else(|| {
        (ids.musicinfo_id > 0)
            .then(|| cover_url("music", &ids.musicinfo_id.to_string()))
            .flatten()
    })
    .or_else(|| {
        (ids.consoleinfo_id > 0)
            .then(|| cover_url("console", &ids.consoleinfo_id.to_string()))
            .flatten()
    })
    .or_else(|| {
        (ids.bookinfo_id > 0)
            .then(|| cover_url("book", &ids.bookinfo_id.to_string()))
            .flatten()
    })
    .or_else(|| {
        (ids.gamesinfo_id > 0)
            .then(|| cover_url("games", &ids.gamesinfo_id.to_string()))
            .flatten()
    })
    .or_else(|| {
        (ids.anidbid > 0)
            .then(|| {
                cover_url("anime", &format!("{}-cover", ids.anidbid))
                    .or_else(|| cover_url("anime", &ids.anidbid.to_string()))
            })
            .flatten()
    })
    .unwrap_or_else(|| "/assets/images/no-cover.png".to_owned());

    let failed: i64 = sqlx::query_scalar(
        "SELECT CAST(COALESCE(SUM(failed), 0) AS SIGNED) FROM dnzb_failures WHERE release_id = ?",
    )
    .bind(id)
    .fetch_one(&ctx.db)
    .await?;

    let report_rows: Vec<ReportRow> = sqlx::query_as(
        "SELECT reason, CAST(status AS CHAR), created_at, description FROM release_reports WHERE releases_id = ? ORDER BY created_at DESC",
    )
    .bind(id)
    .fetch_all(&ctx.db)
    .await?;
    let mut all_reasons: Vec<String> = Vec::new();
    let reports: Vec<ReportView> = report_rows
        .into_iter()
        .map(|(reason, status, created, description)| {
            let label = reason_label(&reason);
            if !all_reasons.contains(&label) {
                all_reasons.push(label.clone());
            }
            ReportView {
                reason: label,
                status: uppercase_first(&status),
                created: created
                    .map(|at| at.format("%b %d, %Y %H:%M").to_string())
                    .unwrap_or_default(),
                description: description
                    .filter(|text| !text.is_empty())
                    .unwrap_or_else(|| "No additional report details were provided.".to_owned()),
            }
        })
        .collect();
    let response_rows: Vec<(String, Option<DateTime<Utc>>, Option<String>)> = sqlx::query_as(
        "SELECT rr.response, rr.responded_at, u.username FROM release_reports rr
         LEFT JOIN users u ON u.id = rr.responded_by
         WHERE rr.releases_id = ? AND rr.response_is_public = 1 AND rr.response IS NOT NULL AND rr.response <> ''
         ORDER BY rr.responded_at DESC",
    )
    .bind(id)
    .fetch_all(&ctx.db)
    .await?;
    let responses = response_rows
        .into_iter()
        .map(|(text, at, by)| ResponseView {
            text,
            when: at.map_or_else(
                || "Staff response".to_owned(),
                |at| format!("Responded {}", at.format("%b %d, %Y %H:%M")),
            ),
            by: by.unwrap_or_default(),
        })
        .collect();

    let comment_rows: Vec<(Option<String>, Option<DateTime<Utc>>, String)> = sqlx::query_as(
        "SELECT username, created_at, text FROM release_comments WHERE releases_id = ? ORDER BY created_at DESC",
    )
    .bind(id)
    .fetch_all(&ctx.db)
    .await?;
    let comments = comment_rows
        .into_iter()
        .map(|(username, at, text)| CommentView {
            username: username
                .filter(|name| !name.is_empty())
                .unwrap_or_else(|| "Anonymous".to_owned()),
            when: at.map(|at| relative(at, now)).unwrap_or_default(),
            text,
        })
        .collect();

    let fingerprint: Option<FingerprintRow> =
        sqlx::query_as("SELECT status, metadata, last_error, collected_at FROM release_fingerprint_metadata WHERE releases_id = ?")
            .bind(id)
            .fetch_optional(&ctx.db)
            .await?;
    let (status, metadata, error, collected) = fingerprint.unwrap_or_default();
    let fingerprint_metadata = metadata.filter(|text| !text.is_empty()).map(|text| {
        serde_json::from_str::<Value>(&text)
            .ok()
            .and_then(|value| serde_json::to_string_pretty(&value).ok())
            .unwrap_or(text)
    });

    let site = &layout.site;
    let panels = load_panels(
        &ctx,
        &ids,
        site.trailers_display != 0,
        &site.dereferrer_link,
    )
    .await?;
    let media = load_media(&ctx.db, id).await?;
    let searchname = release.searchname.clone();
    let page = DetailsPage {
        layout: layout.titled(
            "View NZB",
            "view,nzb,description,details",
            format!("View NZB for {searchname}"),
        ),
        release,
        cover,
        size_gb: format!("{:.2}", size as f64 / 1_073_741_824.0),
        added,
        posted,
        poster_search_url: format!(
            "/search?poster={0}&searchadvposter={0}&search_type=adv",
            encode_component(&fromname)
        ),
        failed,
        all_reasons: if all_reasons.is_empty() {
            "Unknown".to_owned()
        } else {
            all_reasons.join(", ")
        },
        reports,
        responses,
        can_edit: signed_in.access.has_any_role(&["Admin", "Moderator"]),
        has_preview,
        has_sample,
        preview_url: cover_url("preview", &format!("{guid}_thumb"))
            .unwrap_or_else(|| "/assets/images/no-cover.png".to_owned()),
        sample_url: cover_url("sample", &format!("{guid}_thumb"))
            .unwrap_or_else(|| "/assets/images/no-cover.png".to_owned()),
        panels,
        media,
        fingerprint_metadata,
        fingerprint_status: uppercase_first(&status),
        fingerprint_collected: collected
            .map(|at| at.and_utc().to_rfc3339())
            .unwrap_or_default(),
        fingerprint_error: error.unwrap_or_default(),
        comments,
    };
    Ok(Html(page.render()?).into_response())
}

async fn add_comment(
    State(ctx): State<Ctx>,
    session: Session,
    input: Input,
    Path(guid): Path<String>,
    headers: HeaderMap,
    ClientIp(ip): ClientIp,
    CurrentUser(signed_in): CurrentUser,
) -> WebResult {
    let id: Option<i64> =
        sqlx::query_scalar("SELECT CAST(id AS SIGNED) FROM releases WHERE guid = ?")
            .bind(&guid)
            .fetch_optional(&ctx.db)
            .await?;
    let Some(id) = id else {
        return Ok(back(&headers, "/"));
    };
    let store_ip = matches!(
        nntmux_core::var_or("STORE_USER_IP", "false").as_str(),
        "1" | "true" | "on" | "yes"
    );
    let host = if store_ip {
        ip.to_string()
    } else {
        String::new()
    };
    let mut tx = ctx.db.begin().await?;
    sqlx::query("INSERT INTO release_comments (releases_id, text, users_id, host, username, created_at, updated_at) VALUES (?, ?, ?, ?, ?, NOW(), NOW())")
        .bind(id)
        .bind(input.str("txtAddComment"))
        .bind(signed_in.user.id)
        .bind(host)
        .bind(&signed_in.user.username)
        .execute(&mut *tx)
        .await?;
    sqlx::query("UPDATE releases SET comments = (SELECT COUNT(*) FROM release_comments WHERE releases_id = ?) WHERE id = ?")
        .bind(id)
        .bind(id)
        .execute(&mut *tx)
        .await?;
    tx.commit().await?;
    session.flash("success", "Comment posted successfully!");
    Ok(Redirect::to(&format!("/details/{}", path_segment(&guid))).into_response())
}

fn dnzb_error(status: StatusCode, message: &'static str) -> Response {
    let mut response = (status, message).into_response();
    let headers = response.headers_mut();
    headers.insert("x-dnzb-rcode", HeaderValue::from(status.as_u16()));
    headers.insert("x-dnzb-rtext", HeaderValue::from_static(message));
    response
}

/// `(^\w+[-_. ].+?\.(\d+p)).+`: the title up to the resolution, what alternates must share.
fn similar_title(name: &str) -> Option<String> {
    for (index, _) in name.match_indices('.') {
        let rest = &name[index + 1..];
        let digits = rest.bytes().take_while(u8::is_ascii_digit).count();
        if digits > 0 && rest[digits..].to_ascii_lowercase().starts_with('p') {
            let end = index + 1 + digits + 1;
            let leading = name
                .chars()
                .take_while(|c| c.is_alphanumeric() || *c == '_')
                .count();
            let starts_right = leading > 0
                && name
                    .chars()
                    .nth(leading)
                    .is_some_and(|c| matches!(c, '-' | '_' | '.' | ' '));
            if starts_right && end < name.len() {
                return Some(name[..end].to_owned());
            }
        }
    }
    None
}

async fn alternate(ctx: &Ctx, guid: &str, user_id: i64) -> Result<Option<String>> {
    let row: Option<(i64, String, i64)> = sqlx::query_as("SELECT CAST(id AS SIGNED), searchname, CAST(categories_id AS SIGNED) FROM releases WHERE guid = ?")
        .bind(guid)
        .fetch_optional(&ctx.db)
        .await?;
    let Some((id, name, category)) = row else {
        return Ok(None);
    };
    sqlx::query("INSERT IGNORE INTO dnzb_failures (release_id, users_id, failed) VALUES (?, ?, 1)")
        .bind(id)
        .bind(user_id)
        .execute(&ctx.db)
        .await?;
    let Some(similar) = similar_title(&name) else {
        return Ok(None);
    };
    let config = SearchConfig::from_env(&ctx.config.manticore)?;
    let client = SearchClient::new(config, Some(ctx.redis.clone()))?;
    let ids = client
        .search_release_ids(&Phrases::Text(similar), 10)
        .await
        .unwrap_or_default();
    if ids.is_empty() {
        return Ok(None);
    }
    let list = ids
        .iter()
        .map(ToString::to_string)
        .collect::<Vec<_>>()
        .join(",");
    let sql = format!(
        "SELECT r.guid FROM releases r LEFT JOIN dnzb_failures df ON df.release_id = r.id
         WHERE r.id IN ({list}) AND df.release_id IS NULL AND r.categories_id = ? AND r.id <> ?
         ORDER BY r.postdate DESC LIMIT 1"
    );
    Ok(sqlx::query_scalar(sqlx::AssertSqlSafe(sql))
        .bind(category)
        .bind(id)
        .fetch_optional(&ctx.db)
        .await?)
}

/// The DNZB failure report. A download client sends an API token; a browser must POST inside
/// its session, with its CSRF token, which the web stack can't check for this exempt path.
async fn failed(
    State(ctx): State<Ctx>,
    method: Method,
    input: Input,
    session: Session,
    MaybeUser(signed_in): MaybeUser,
    headers: HeaderMap,
) -> WebResult {
    let has_token = input.has("api_token");
    if !has_token {
        if method != Method::POST {
            return Ok((
                StatusCode::METHOD_NOT_ALLOWED,
                "Browser failure reports require POST.",
            )
                .into_response());
        }
        let sent = input.get("_token").or_else(|| {
            headers
                .get("x-csrf-token")
                .and_then(|value| value.to_str().ok())
                .map(str::to_owned)
        });
        if !sent.is_some_and(|sent| config::constant_time_eq(&sent, &session.token())) {
            return Err(nntmux_webkit::WebError::PageExpired);
        }
    }
    if !input.has("guid") {
        return Ok(dnzb_error(
            StatusCode::BAD_REQUEST,
            "Bad request, please supply all parameters!",
        ));
    }
    let token = input.get("api_token");
    let user_id = if has_token {
        match &token {
            Some(token) => User::find_by(&ctx.db, "api_token", token)
                .await?
                .filter(User::has_verified_email)
                .map(|user| user.id),
            None => None,
        }
    } else {
        signed_in.map(|auth| auth.user.id)
    };
    let Some(user_id) = user_id else {
        return Ok(dnzb_error(
            StatusCode::UNAUTHORIZED,
            "Unauthorised, wrong rss key!",
        ));
    };
    let Some(alt) = alternate(&ctx, &input.str("guid"), user_id).await? else {
        return Ok(dnzb_error(
            StatusCode::NOT_FOUND,
            "No NZB found for alternate match!",
        ));
    };
    let mut location = format!("{}/getnzb?id={}", config::app_url(), encode_component(&alt));
    if let Some(token) = token {
        location.push_str(&format!("&r={}", encode_component(&token)));
    }
    let mut response = "Success".into_response();
    response
        .headers_mut()
        .insert(header::LOCATION, HeaderValue::from_str(&location)?);
    Ok(response)
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn similar_titles_stop_at_the_resolution() {
        assert_eq!(
            similar_title("Some.Movie.2024.1080p.WEB-DL").as_deref(),
            Some("Some.Movie.2024.1080p")
        );
        assert_eq!(similar_title("NoResolution.Here"), None);
        assert_eq!(
            similar_title("Ends.At.720p"),
            None,
            "PHP's pattern wants something after it"
        );
    }

    #[test]
    fn field_links_cap_at_eight_and_encode() {
        let names = (0..10)
            .map(|n| format!("Actor {n}"))
            .collect::<Vec<_>>()
            .join(", ");
        let field = field_links("Cast", &names, "Movies", "actors").unwrap();
        assert_eq!(field.links.len(), 8);
        assert_eq!(field.links[0].href, "/Movies?actors=Actor+0");
        assert!(field_links("Cast", "", "Movies", "actors").is_none());
    }

    #[test]
    fn only_web_trailers_reach_an_iframe() {
        assert!(safe_trailer("javascript:alert(1)").is_none());
        assert!(safe_trailer("https://example.com/x").is_some());
    }
}
