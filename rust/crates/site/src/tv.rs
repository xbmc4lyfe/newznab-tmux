//! TV series pages, trending TV, and the My Shows and My Movies lists.

use std::collections::BTreeMap;
use std::sync::LazyLock;

use anyhow::Result;
use askama::Template;
use axum::extract::{Path, State};
use axum::http::{HeaderMap, Method, StatusCode};
use axum::middleware::from_fn;
use axum::response::{Html, IntoResponse, Redirect};
use axum::routing::get;
use axum::{Json, Router};
use chrono::{DateTime, NaiveDateTime, Utc};
use nntmux_core::{parsed_or, var_or};
use nntmux_runtime::Ctx;
use nntmux_webkit::auth::Auth;
use nntmux_webkit::config::encode_component;
use nntmux_webkit::{CurrentUser, Input, Layout, Pager, WebError, WebResult, guard, user};
use regex::Regex;
use serde_json::json;
use sqlx::MySqlPool;

use crate::auth::back;
use crate::browse::clearance;
use crate::releases::{
    BrowseFilter, ReleaseList, browse_count, browse_rows, cover_url, expand_categories, id_list,
    local_time, order_or_default, password_limit, per_page,
};

const TV_ROOT: i64 = 5000;
const TV_OTHER: i64 = 5999;
const MOVIE_ROOT: i64 = 2000;
const TV_WEBDL: i64 = 5010;
const MOVIE_WEBDL: i64 = 2080;
const MOVIE_OTHER: i64 = 2999;
/// How many unmatched releases `fallbackCandidates` scanned.
const FALLBACK_SCAN_LIMIT: u32 = 500;

pub fn router() -> Router<Ctx> {
    Router::new()
        .route("/series", get(series_list).post(series_list))
        .route("/series/{id}", get(series).post(series))
        .route("/trending-tv", get(trending_tv).post(trending_tv))
        .route("/myshows", get(my_shows).post(my_shows))
        .route(
            "/myshows/browse",
            get(my_shows_browse).post(my_shows_browse),
        )
        .route("/mymovies", get(my_movies).post(my_movies))
        .route_layer(from_fn(clearance))
        .route_layer(from_fn(guard::two_factor))
        .route_layer(from_fn(guard::verified))
        .route_layer(from_fn(guard::authenticated))
}

struct ShowRow {
    id: i64,
    title: String,
    episode: String,
    aired: String,
    publisher: String,
    following: bool,
}

#[derive(Template)]
#[template(path = "tv/list.html")]
struct ListPage {
    layout: Layout,
    shows: Vec<ShowRow>,
    ranges: Vec<String>,
    letter: String,
    showname: String,
}

async fn list_page(
    ctx: &Ctx,
    layout: Layout,
    input: &Input,
    signed_in: &Auth,
    id: Option<String>,
) -> WebResult {
    let showname = input.str("title");
    let valid_letter = id.as_deref().is_some_and(|id| {
        id == "0-9" || (id.len() == 1 && id.chars().all(|c| c.is_ascii_alphabetic()))
    });
    let letter = if valid_letter {
        id.clone().unwrap_or_default()
    } else if !showname.is_empty() && id.is_none() {
        String::new()
    } else {
        "0-9".to_owned()
    };
    let mut conditions = String::new();
    let mut binds: Vec<String> = Vec::new();
    if letter == "0-9" {
        conditions.push_str(" AND v.title REGEXP '^[0-9]'");
    } else if !letter.is_empty() {
        conditions.push_str(" AND v.title LIKE ?");
        binds.push(format!("{letter}%"));
    }
    if !showname.is_empty() {
        conditions.push_str(" AND v.title LIKE ?");
        binds.push(format!("%{showname}%"));
    }
    let sql = format!(
        "SELECT CAST(v.id AS SIGNED), v.title, MAX(tve.title), CAST(MAX(tve.firstaired) AS CHAR), MAX(tvi.publisher), CAST(MAX(us.id) AS SIGNED)
         FROM videos v
         INNER JOIN tv_info tvi ON v.id = tvi.videos_id
         INNER JOIN (SELECT videos_id, MAX(firstaired) AS latest FROM tv_episodes WHERE firstaired < NOW() GROUP BY videos_id) l ON v.id = l.videos_id
         INNER JOIN tv_episodes tve ON v.id = tve.videos_id AND tve.firstaired = l.latest
         LEFT JOIN user_series us ON v.id = us.videos_id AND us.users_id = ?
         WHERE EXISTS (SELECT 1 FROM releases r WHERE r.videos_id = v.id AND r.categories_id BETWEEN {TV_ROOT} AND {TV_OTHER})
         {conditions}
         GROUP BY v.id, v.title ORDER BY v.title ASC"
    );
    let mut query = sqlx::query_as::<
        _,
        (
            i64,
            String,
            Option<String>,
            Option<String>,
            Option<String>,
            Option<i64>,
        ),
    >(sqlx::AssertSqlSafe(sql))
    .bind(signed_in.user.id);
    for bind in &binds {
        query = query.bind(bind);
    }
    let rows = query.fetch_all(&ctx.db).await?;
    let shows = rows
        .into_iter()
        .map(
            |(id, title, episode, aired, publisher, following)| ShowRow {
                id,
                title,
                episode: episode.unwrap_or_default(),
                aired: aired
                    .unwrap_or_default()
                    .split(' ')
                    .next()
                    .unwrap_or_default()
                    .to_owned(),
                publisher: publisher.unwrap_or_default(),
                following: following.is_some(),
            },
        )
        .collect();
    let mut ranges = vec!["0-9".to_owned()];
    ranges.extend(('A'..='Z').map(String::from));
    let page = ListPage {
        layout: layout.titled(
            "View Series List",
            "view,series,tv,show,description,details",
            "View Series List",
        ),
        shows,
        ranges,
        letter,
        showname,
    };
    Ok(Html(page.render()?).into_response())
}

async fn series_list(
    State(ctx): State<Ctx>,
    layout: Layout,
    input: Input,
    CurrentUser(signed_in): CurrentUser,
) -> WebResult {
    list_page(&ctx, layout, &input, &signed_in, None).await
}

type EpisodeRow = (
    i64,
    Option<String>,
    String,
    String,
    i64,
    Option<NaiveDateTime>,
    i64,
);

fn pattern(source: &str) -> Regex {
    Regex::new(source).expect("a valid literal pattern")
}

static SEASON_PATTERNS: LazyLock<[Regex; 3]> = LazyLock::new(|| {
    [
        pattern(r"(?i)\bS(\d{1,2})E(\d{1,3})\b"),
        pattern(r"(?i)\b(\d{1,2})x(\d{1,3})\b"),
        pattern(r"(?i)\bSeason[\s._-]*(\d{1,2})[\s._-]*Episode[\s._-]*(\d{1,3})\b"),
    ]
});
static COMPACT: LazyLock<Regex> = LazyLock::new(|| pattern(r"\b(\d)(0[1-9]|[1-9]\d)\b"));
static DATED: LazyLock<Regex> = LazyLock::new(|| pattern(r"\b(\d{4})[._-](\d{2})[._-](\d{2})\b"));
static PART: LazyLock<Regex> = LazyLock::new(|| pattern(r"(?i)\b(?:Part|Pt)[\s._-]*(\d{1,3})\b"));
static EPISODE_ONLY: LazyLock<Regex> = LazyLock::new(|| pattern(r"(?i)\bEp?[\s._-]*(\d{1,3})\b"));

/// `hydrateFromSearchname`: season and episode from a release name.
fn parse_episode(name: &str) -> Option<(i64, i64)> {
    let number = |text: &str| text.parse::<i64>().unwrap_or(0);
    for regex in SEASON_PATTERNS.iter() {
        if let Some(found) = regex.captures(name) {
            return Some((number(&found[1]), number(&found[2])));
        }
    }
    if let Some(found) = COMPACT.captures(name) {
        return Some((number(&found[1]), number(&found[2])));
    }
    if let Some(found) = DATED.captures(name) {
        return Some((
            number(&found[1]),
            number(&format!("{}{}", &found[2], &found[3])),
        ));
    }
    if let Some(found) = PART.captures(name).or_else(|| EPISODE_ONLY.captures(name)) {
        return Some((1, number(&found[1])));
    }
    None
}

struct SeasonTab {
    season: i64,
    count: i64,
    url: String,
    active: bool,
}

struct EpisodeRelease {
    guid: String,
    name: String,
    size: String,
    posted: String,
    failed: i64,
}

struct Episode {
    number: i64,
    aired: String,
    releases: Vec<EpisodeRelease>,
}

#[derive(Template)]
#[template(path = "tv/view.html")]
struct ViewPage {
    layout: Layout,
    title: String,
    show_id: i64,
    following: bool,
    nodata: &'static str,
    summary: String,
    country: String,
    seasons_aired: i64,
    episodes_aired: i64,
    first_aired: String,
    last_aired: String,
    tabs: Vec<SeasonTab>,
    episodes: Vec<Episode>,
    pager: Pager,
}

#[derive(Template)]
#[template(path = "tv/season.html")]
struct SeasonFragment {
    episodes: Vec<Episode>,
}

#[derive(Template)]
#[template(path = "pager.html")]
struct PagerFragment {
    pager: Pager,
}

fn series_url(id: i64, query: &[(String, String)], season: i64, page: u64) -> String {
    let mut url = format!("/series/{id}?");
    for (key, value) in query
        .iter()
        .filter(|(key, _)| !matches!(key.as_str(), "season" | "_fragment"))
    {
        url.push_str(&format!(
            "{}={}&",
            encode_component(key),
            encode_component(value)
        ));
    }
    url.push_str(&format!("season={season}&page={page}"));
    url
}

async fn series(
    State(ctx): State<Ctx>,
    layout: Layout,
    input: Input,
    Path(id): Path<String>,
    CurrentUser(signed_in): CurrentUser,
) -> WebResult {
    if id.is_empty() || !id.bytes().all(|byte| byte.is_ascii_digit()) {
        return list_page(&ctx, layout, &input, &signed_in, Some(id)).await;
    }
    let video: i64 = id.parse().map_err(|_| WebError::NotFound)?;
    let limit: u64 = parsed_or("SERIES_VIEW_LIMIT", per_page())
        .unwrap_or(50)
        .max(1);
    let db = &ctx.db;
    let show: Option<(i64, String, Option<String>, Option<String>)> = sqlx::query_as(
        "SELECT CAST(v.id AS SIGNED), v.title, MAX(ti.summary), v.countries_id FROM videos v
         LEFT JOIN tv_info ti ON ti.videos_id = v.id WHERE v.id = ? GROUP BY v.id, v.title, v.countries_id",
    )
    .bind(video)
    .fetch_optional(db)
    .await?;
    let empty_pager = || Pager::new(&format!("/series/{id}"), &[], 0, limit, 1);
    let layout = layout.titled(
        "View TV Series",
        "view,series,tv,show,description,details",
        "View TV Series",
    );
    let Some((show_id, title, summary, country)) = show else {
        let page = ViewPage {
            layout,
            title: "TV series".to_owned(),
            show_id: 0,
            following: false,
            nodata: "No video information for this series.",
            summary: String::new(),
            country: String::new(),
            seasons_aired: 0,
            episodes_aired: 0,
            first_aired: String::new(),
            last_aired: String::new(),
            tabs: Vec::new(),
            episodes: Vec::new(),
            pager: empty_pager(),
        };
        return Ok(Html(page.render()?).into_response());
    };
    let following: i64 =
        sqlx::query_scalar("SELECT COUNT(*) FROM user_series WHERE users_id = ? AND videos_id = ?")
            .bind(signed_in.user.id)
            .bind(video)
            .fetch_one(db)
            .await?;
    let category = input
        .get("t")
        .filter(|t| t.bytes().all(|byte| byte.is_ascii_digit()))
        .and_then(|t| t.parse::<i64>().ok())
        .filter(|t| *t > 0)
        .unwrap_or(TV_ROOT);
    let excluded = user::category_exclusions(db, signed_in.user.id, &signed_in.access).await?;
    let mut clause = format!(" AND r.passwordstatus <= {}", password_limit(db).await?);
    if let Some(ids) = expand_categories(db, &[category]).await? {
        clause.push_str(&format!(" AND r.categories_id IN ({})", id_list(&ids)));
    }
    if !excluded.is_empty() {
        clause.push_str(&format!(
            " AND r.categories_id NOT IN ({})",
            id_list(&excluded)
        ));
    }
    let matched_counts: Vec<(i64, i64)> = sqlx::query_as(sqlx::AssertSqlSafe(format!(
        "SELECT CAST(tve.series AS SIGNED), CAST(COUNT(*) AS SIGNED) FROM releases r
         JOIN tv_episodes tve ON r.tv_episodes_id = tve.id
         WHERE r.videos_id = ? AND tve.videos_id = ? {clause} GROUP BY tve.series"
    )))
    .bind(video)
    .bind(video)
    .fetch_all(db)
    .await?;
    // Releases without an episode row take their season from the name, newest first.
    let fallback_rows: Vec<EpisodeRow> = sqlx::query_as(sqlx::AssertSqlSafe(format!(
        "SELECT CAST(0 AS SIGNED), CAST(NULL AS CHAR), r.guid, r.searchname, CAST(r.size AS SIGNED), r.postdate,
                CAST((SELECT COUNT(*) FROM dnzb_failures df WHERE df.release_id = r.id) AS SIGNED)
         FROM releases r
         WHERE r.videos_id = ? AND (r.tv_episodes_id IS NULL OR r.tv_episodes_id <= 0) {clause}
         ORDER BY r.postdate DESC LIMIT {FALLBACK_SCAN_LIMIT}"
    )))
    .bind(video)
    .fetch_all(db)
    .await?;
    let fallback: Vec<(i64, i64, EpisodeRow)> = fallback_rows
        .into_iter()
        .filter_map(|row| parse_episode(&row.3).map(|(season, episode)| (season, episode, row)))
        .collect();
    let mut counts: BTreeMap<i64, i64> = matched_counts.iter().copied().collect();
    for (season, ..) in &fallback {
        *counts.entry(*season).or_insert(0) += 1;
    }
    let requested = input
        .get("season")
        .filter(|s| s.bytes().all(|byte| byte.is_ascii_digit()))
        .and_then(|s| s.parse::<i64>().ok());
    let selected = requested
        .filter(|season| counts.contains_key(season))
        .or(counts.keys().next().copied());

    let query = input.query_pairs();
    let total = selected
        .and_then(|season| counts.get(&season))
        .copied()
        .unwrap_or(0)
        .max(0) as u64;
    let mut pager_query: Vec<(String, String)> = query
        .iter()
        .filter(|(key, _)| key != "_fragment")
        .cloned()
        .collect();
    if let Some(season) = selected
        && !pager_query.iter().any(|(key, _)| key == "season")
    {
        pager_query.push(("season".to_owned(), season.to_string()));
    }
    let pager = Pager::new(
        &format!("/series/{id}"),
        &pager_query,
        total,
        limit,
        input.page(),
    );
    let mut episodes: Vec<Episode> = Vec::new();
    if let Some(season) = selected.filter(|_| pager.offset() < total) {
        let matched_total = matched_counts
            .iter()
            .find(|(found, _)| *found == season)
            .map_or(0, |(_, count)| *count)
            .max(0) as u64;
        let offset = pager.offset();
        let mut rows: Vec<(i64, EpisodeRow)> = Vec::new();
        if offset < matched_total {
            let sql = format!(
                "SELECT CAST(tve.episode AS SIGNED), CAST(tve.firstaired AS CHAR), r.guid, r.searchname, CAST(r.size AS SIGNED), r.postdate,
                        CAST((SELECT COUNT(*) FROM dnzb_failures df WHERE df.release_id = r.id) AS SIGNED)
                 FROM releases r JOIN tv_episodes tve ON r.tv_episodes_id = tve.id
                 WHERE r.videos_id = ? AND tve.videos_id = ? AND tve.series = ? {clause}
                 ORDER BY r.postdate DESC LIMIT {} OFFSET {offset}",
                pager.per_page
            );
            let matched: Vec<EpisodeRow> = sqlx::query_as(sqlx::AssertSqlSafe(sql))
                .bind(video)
                .bind(video)
                .bind(season)
                .fetch_all(db)
                .await?;
            rows.extend(matched.into_iter().map(|row| (row.0, row)));
        }
        let skip = offset.saturating_sub(matched_total) as usize;
        let room = (pager.per_page as usize).saturating_sub(rows.len());
        rows.extend(
            fallback
                .into_iter()
                .filter(|(found, ..)| *found == season)
                .skip(skip)
                .take(room)
                .map(|(_, episode, row)| (episode, row)),
        );
        for (number, (_, aired, guid, name, size, posted, failed)) in rows {
            let release = EpisodeRelease {
                guid,
                name,
                size: format!("{:.2} GB", size as f64 / 1_073_741_824.0),
                posted: posted
                    .map(|at| local_time(at, signed_in.user.timezone.as_deref(), "%b %d, %Y"))
                    .unwrap_or_default(),
                failed,
            };
            match episodes.iter_mut().find(|episode| episode.number == number) {
                Some(episode) => episode.releases.push(release),
                None => episodes.push(Episode {
                    number,
                    aired: aired
                        .unwrap_or_default()
                        .split(" ")
                        .next()
                        .unwrap_or_default()
                        .to_owned(),
                    releases: vec![release],
                }),
            }
        }
        episodes.sort_by_key(|episode| episode.number);
    }
    let selected_season = selected;
    if input.str("_fragment") == "season" {
        let url = selected_season.map_or_else(
            || format!("/series/{id}"),
            |season| series_url(video, &query, season, input.page()),
        );
        return Ok(Json(json!({
            "selectedSeason": selected_season,
            "contentHtml": SeasonFragment { episodes }.render()?,
            "paginationHtml": PagerFragment { pager }.render()?,
            "url": url,
        }))
        .into_response());
    }
    let stats: (Option<String>, Option<String>, i64, i64) = sqlx::query_as(
        "SELECT CAST(MIN(firstaired) AS CHAR), CAST(MAX(firstaired) AS CHAR), CAST(COUNT(DISTINCT series) AS SIGNED), CAST(COUNT(*) AS SIGNED)
         FROM tv_episodes WHERE videos_id = ? AND firstaired IS NOT NULL AND firstaired <> ''",
    )
    .bind(video)
    .fetch_one(db)
    .await?;
    let tabs = counts
        .iter()
        .map(|(season, count)| SeasonTab {
            season: *season,
            count: *count,
            url: format!("{}#series-episodes", series_url(video, &query, *season, 1)),
            active: Some(*season) == selected_season,
        })
        .collect();
    let page = ViewPage {
        layout,
        title,
        show_id,
        following: following > 0,
        nodata: if counts.is_empty() {
            "No releases for this series."
        } else {
            ""
        },
        summary: summary.unwrap_or_default(),
        country: country.unwrap_or_default(),
        seasons_aired: stats.2,
        episodes_aired: stats.3,
        first_aired: stats
            .0
            .unwrap_or_default()
            .split(' ')
            .next()
            .unwrap_or_default()
            .to_owned(),
        last_aired: stats
            .1
            .unwrap_or_default()
            .split(' ')
            .next()
            .unwrap_or_default()
            .to_owned(),
        tabs,
        episodes,
        pager,
    };
    Ok(Html(page.render()?).into_response())
}

struct TrendingItem {
    title: String,
    detail: String,
    cover: String,
    href: String,
    downloads: i64,
    releases: i64,
}

#[derive(Template)]
#[template(path = "covers/trending.html")]
struct TrendingPage {
    layout: Layout,
    page_title: &'static str,
    items: Vec<TrendingItem>,
}

async fn trending_tv(State(ctx): State<Ctx>, layout: Layout) -> WebResult {
    let rows: Vec<(i64, String, Option<String>, i64, i64)> = sqlx::query_as(
        "SELECT CAST(v.id AS SIGNED), v.title, CAST(v.started AS CHAR),
                CAST(COUNT(DISTINCT ud.id) AS SIGNED) AS total_downloads, CAST(COUNT(DISTINCT r.id) AS SIGNED)
         FROM videos v
         JOIN tv_info ti ON v.id = ti.videos_id
         JOIN releases r ON v.id = r.videos_id
         JOIN user_downloads ud ON r.id = ud.releases_id
         WHERE v.type = 0 AND v.title <> '' AND ud.timestamp >= DATE_SUB(NOW(), INTERVAL 48 HOUR)
         GROUP BY v.id, v.title, v.started
         ORDER BY total_downloads DESC LIMIT 15",
    )
    .fetch_all(&ctx.db)
    .await?;
    let items = rows
        .into_iter()
        .map(|(id, title, started, downloads, releases)| TrendingItem {
            cover: cover_url("tvshows", &id.to_string())
                .unwrap_or_else(|| "/assets/images/no-cover.png".to_owned()),
            href: format!("/series/{id}"),
            title,
            detail: started
                .unwrap_or_default()
                .split(' ')
                .next()
                .unwrap_or_default()
                .to_owned(),
            downloads,
            releases,
        })
        .collect();
    let layout = layout.titled(
        "Trending TV Shows - Last 48 Hours",
        "trending,tv,shows,series,popular,downloads,recent",
        "Browse the most popular and downloaded TV shows in the last 48 hours",
    );
    Ok(Html(
        TrendingPage {
            layout,
            page_title: "Trending TV",
            items,
        }
        .render()?,
    )
    .into_response())
}

struct ListItem {
    title: String,
    href: String,
    categories: String,
    added: String,
    edit_url: String,
    delete_url: String,
}

#[derive(Template)]
#[template(path = "tv/my_list.html")]
struct MyListPage {
    layout: Layout,
    heading: &'static str,
    description: &'static str,
    icon: &'static str,
    browse_url: &'static str,
    rss_url: String,
    items: Vec<ListItem>,
}

struct CategoryChoice {
    id: i64,
    title: String,
    selected: bool,
}

#[derive(Template)]
#[template(path = "tv/my_form.html")]
struct MyFormPage {
    layout: Layout,
    heading: &'static str,
    title: String,
    action: &'static str,
    action_field: &'static str,
    action_value: &'static str,
    id_field: &'static str,
    id_value: String,
    from: String,
    categories: Vec<CategoryChoice>,
}

/// A root's children, optionally without WEB-DL when the operator disabled it.
async fn children(
    db: &MySqlPool,
    root: i64,
    webdl: i64,
    hide_webdl: bool,
) -> Result<Vec<(i64, String)>> {
    let hide = hide_webdl && var_or("CATWEBDL", "1") == "0";
    let rows: Vec<(i64, String)> = sqlx::query_as(
        "SELECT CAST(id AS SIGNED), title FROM categories WHERE root_categories_id = ? ORDER BY id",
    )
    .bind(root)
    .fetch_all(db)
    .await?;
    Ok(rows
        .into_iter()
        .filter(|(id, _)| !(hide && *id == webdl))
        .collect())
}

fn names_for(stored: &str, categories: &[(i64, String)]) -> String {
    stored
        .split('|')
        .filter_map(|id| id.parse::<i64>().ok())
        .filter_map(|id| {
            categories
                .iter()
                .find(|(found, _)| *found == id)
                .map(|(_, title)| title.clone())
        })
        .collect::<Vec<_>>()
        .join(", ")
}

/// Stored as PHP stored it: ids joined by `|`, or the text `NULL` for none.
fn stored_categories(input: &Input, allowed: &[(i64, String)]) -> String {
    let mut selected: Vec<i64> = Vec::new();
    for id in input
        .list("category")
        .iter()
        .filter_map(|id| id.parse::<i64>().ok())
    {
        if allowed.iter().any(|(found, _)| *found == id) && !selected.contains(&id) {
            selected.push(id);
        }
    }
    if selected.is_empty() {
        return "NULL".to_owned();
    }
    selected
        .iter()
        .map(i64::to_string)
        .collect::<Vec<_>>()
        .join("|")
}

/// `localReturnUrl`: only a path on this site.
fn local_return(input: &Input, fallback: &str) -> String {
    let from = input.str("from");
    let unsafe_chars = from
        .chars()
        .any(|c| c.is_control() || c == ' ' || c == '\\');
    if from.is_empty()
        || unsafe_chars
        || from.starts_with("//")
        || from.contains("://")
        || !from.starts_with('/')
    {
        return fallback.to_owned();
    }
    from
}

fn added(at: Option<DateTime<Utc>>) -> String {
    at.map(|at| at.format("%b %d, %Y").to_string())
        .unwrap_or_default()
}

const LIST_KEYWORDS: &str = "search,add,to,cart,nzb,description,details";

async fn my_shows(
    State(ctx): State<Ctx>,
    layout: Layout,
    input: Input,
    method: Method,
    headers: HeaderMap,
    CurrentUser(signed_in): CurrentUser,
) -> WebResult {
    let action = input.str("action");
    if matches!(action.as_str(), "delete" | "doadd" | "doedit") && method != Method::POST {
        return Ok(StatusCode::METHOD_NOT_ALLOWED.into_response());
    }
    let db = &ctx.db;
    let user_id = signed_in.user.id;
    let video = input.str("id");
    let from = local_return(&input, "/myshows");
    let row: Option<Option<String>> = sqlx::query_scalar(
        "SELECT categories FROM user_series WHERE users_id = ? AND videos_id = ?",
    )
    .bind(user_id)
    .bind(&video)
    .fetch_optional(db)
    .await?;
    let form = |heading, title: String, action_value, categories| MyFormPage {
        layout: layout
            .clone()
            .titled(heading, LIST_KEYWORDS, "Manage Your Shows"),
        heading,
        title,
        action: "/myshows",
        action_field: "action",
        action_value,
        id_field: "id",
        id_value: video.clone(),
        from: from.clone(),
        categories,
    };
    match action.as_str() {
        "delete" => {
            if row.is_none() {
                return Ok(back(&headers, "/myshows"));
            }
            sqlx::query("DELETE FROM user_series WHERE users_id = ? AND videos_id = ?")
                .bind(user_id)
                .bind(&video)
                .execute(db)
                .await?;
            Ok(Redirect::to(&from).into_response())
        }
        "add" | "doadd" => {
            let title: Option<String> = sqlx::query_scalar("SELECT title FROM videos WHERE id = ?")
                .bind(&video)
                .fetch_optional(db)
                .await?;
            let (None, Some(title)) = (row, title) else {
                return Ok(Redirect::to("/myshows").into_response());
            };
            let allowed = children(db, TV_ROOT, TV_WEBDL, true).await?;
            if action == "doadd" {
                sqlx::query("INSERT INTO user_series (users_id, videos_id, categories, created_at, updated_at) VALUES (?, ?, ?, NOW(), NOW())")
                    .bind(user_id)
                    .bind(&video)
                    .bind(stored_categories(&input, &allowed))
                    .execute(db)
                    .await?;
                return Ok(Redirect::to(&from).into_response());
            }
            let categories = allowed
                .into_iter()
                .map(|(id, title)| CategoryChoice {
                    id,
                    title,
                    selected: false,
                })
                .collect();
            Ok(Html(form("Add Show", title, "doadd", categories).render()?).into_response())
        }
        "edit" | "doedit" => {
            let Some(stored) = row else {
                return Ok(Redirect::to("/myshows").into_response());
            };
            let allowed = children(db, TV_ROOT, TV_WEBDL, false).await?;
            if action == "doedit" {
                sqlx::query("UPDATE user_series SET categories = ?, updated_at = NOW() WHERE users_id = ? AND videos_id = ?")
                    .bind(stored_categories(&input, &allowed))
                    .bind(user_id)
                    .bind(&video)
                    .execute(db)
                    .await?;
                return Ok(Redirect::to(&from).into_response());
            }
            let title: Option<String> = sqlx::query_scalar("SELECT title FROM videos WHERE id = ?")
                .bind(&video)
                .fetch_optional(db)
                .await?;
            let stored = stored.unwrap_or_default();
            let selected: Vec<&str> = stored.split('|').collect();
            let categories = allowed
                .into_iter()
                .map(|(id, title)| CategoryChoice {
                    selected: selected.contains(&id.to_string().as_str()),
                    id,
                    title,
                })
                .collect();
            Ok(
                Html(form("Edit Show", title.unwrap_or_default(), "doedit", categories).render()?)
                    .into_response(),
            )
        }
        _ => {
            let categories = children(db, TV_ROOT, TV_WEBDL, false).await?;
            let rows: Vec<(i64, String, Option<String>, Option<DateTime<Utc>>)> = sqlx::query_as(
                "SELECT CAST(us.videos_id AS SIGNED), v.title, us.categories, us.created_at FROM user_series us
                 JOIN videos v ON v.id = us.videos_id WHERE us.users_id = ? ORDER BY v.title",
            )
            .bind(user_id)
            .fetch_all(db)
            .await?;
            let items = rows
                .into_iter()
                .map(|(id, title, stored, at)| ListItem {
                    title,
                    href: format!("/series/{id}"),
                    categories: names_for(stored.as_deref().unwrap_or_default(), &categories),
                    added: added(at),
                    edit_url: format!("/myshows?action=edit&id={id}"),
                    delete_url: format!("/myshows?action=delete&id={id}"),
                })
                .collect();
            let page = MyListPage {
                layout: layout.titled("My Shows", LIST_KEYWORDS, "Manage Your Shows"),
                heading: "My Shows",
                description: "Shows you follow, and the categories you want from each.",
                icon: "fas fa-tv",
                browse_url: "/myshows/browse",
                rss_url: format!(
                    "/rss/myshows?dl=1&i={user_id}&api_token={}",
                    signed_in.user.api_token
                ),
                items,
            };
            Ok(Html(page.render()?).into_response())
        }
    }
}

/// `uSQL`: each followed title, narrowed to its chosen categories. Ids come from stored rows and
/// are parsed to numbers here, so nothing stored can reach the SQL as text.
fn followed_clause(column: &str, follows: &[(String, Option<String>)]) -> (String, Vec<String>) {
    let mut sql = String::from(" AND (1=2");
    let mut binds = Vec::new();
    for (key, stored) in follows {
        sql.push_str(&format!(" OR (r.{column} = ?"));
        binds.push(key.clone());
        let ids: Vec<i64> = stored
            .as_deref()
            .unwrap_or_default()
            .split('|')
            .filter_map(|id| id.parse().ok())
            .collect();
        if !ids.is_empty() {
            sql.push_str(&format!(
                " AND r.categories_id IN ({})",
                ids.iter().map(i64::to_string).collect::<Vec<_>>().join(",")
            ));
        }
        sql.push(')');
    }
    sql.push(')');
    (sql, binds)
}

#[derive(Template)]
#[template(path = "browse/index.html")]
struct FollowedPage {
    layout: Layout,
    browse_title: String,
    category_label: String,
    list: ReleaseList,
}

struct Followed<'a> {
    path: &'a str,
    heading: &'a str,
    description: &'a str,
    column: &'a str,
    /// The root's category range, as `getShowsRange` limited it.
    categories: (i64, i64),
}

async fn followed_page(
    ctx: &Ctx,
    layout: Layout,
    input: &Input,
    signed_in: &Auth,
    list: Followed<'_>,
    follows: &[(String, Option<String>)],
) -> WebResult {
    let excluded = user::category_exclusions(&ctx.db, signed_in.user.id, &signed_in.access).await?;
    let (mut extra, binds) = followed_clause(list.column, follows);
    extra.push_str(&format!(
        " AND r.categories_id BETWEEN {} AND {}",
        list.categories.0, list.categories.1
    ));
    let filter = BrowseFilter {
        categories: &[-1],
        excluded: &excluded,
        group: None,
        extra: &extra,
        extra_binds: &binds,
    };
    let total = browse_count(&ctx.db, &filter).await?;
    let limit = per_page();
    let offset = input.page().saturating_sub(1).saturating_mul(limit);
    let rows = if offset >= total {
        Vec::new()
    } else {
        browse_rows(
            &ctx.db,
            &filter,
            order_or_default(&input.str("ob")),
            limit,
            offset,
            signed_in.user.timezone.as_deref(),
        )
        .await?
    };
    let page = FollowedPage {
        layout: layout.titled(list.heading, LIST_KEYWORDS, list.description),
        browse_title: list.heading.to_owned(),
        category_label: "All".to_owned(),
        list: ReleaseList::new(list.path, input, rows, total),
    };
    Ok(Html(page.render()?).into_response())
}

async fn my_shows_browse(
    State(ctx): State<Ctx>,
    layout: Layout,
    input: Input,
    CurrentUser(signed_in): CurrentUser,
) -> WebResult {
    let follows: Vec<(String, Option<String>)> = sqlx::query_as(
        "SELECT CAST(videos_id AS CHAR), categories FROM user_series WHERE users_id = ?",
    )
    .bind(signed_in.user.id)
    .fetch_all(&ctx.db)
    .await?;
    followed_page(
        &ctx,
        layout,
        &input,
        &signed_in,
        Followed {
            path: "/myshows/browse",
            heading: "My Shows",
            description: "Browse Your Shows",
            column: "videos_id",
            categories: (TV_ROOT, TV_OTHER),
        },
        &follows,
    )
    .await
}

async fn my_movies(
    State(ctx): State<Ctx>,
    layout: Layout,
    input: Input,
    method: Method,
    CurrentUser(signed_in): CurrentUser,
) -> WebResult {
    let action = input.str("id");
    if matches!(action.as_str(), "delete" | "doadd" | "doedit") && method != Method::POST {
        return Ok(StatusCode::METHOD_NOT_ALLOWED.into_response());
    }
    let db = &ctx.db;
    let user_id = signed_in.user.id;
    let imdb = input.str("imdb");
    let from = local_return(&input, "/mymovies");
    let row: Option<Option<String>> =
        sqlx::query_scalar("SELECT categories FROM user_movies WHERE users_id = ? AND imdbid = ?")
            .bind(user_id)
            .bind(&imdb)
            .fetch_optional(db)
            .await?;
    let form = |heading, title: String, action_value, categories| MyFormPage {
        layout: layout
            .clone()
            .titled(heading, LIST_KEYWORDS, "Manage Your Movies"),
        heading,
        title,
        action: "/mymovies",
        action_field: "id",
        action_value,
        id_field: "imdb",
        id_value: imdb.clone(),
        from: from.clone(),
        categories,
    };
    match action.as_str() {
        "delete" => {
            if row.is_none() {
                return Ok(Redirect::to("/mymovies").into_response());
            }
            sqlx::query("DELETE FROM user_movies WHERE users_id = ? AND imdbid = ?")
                .bind(user_id)
                .bind(&imdb)
                .execute(db)
                .await?;
            Ok(Redirect::to(&from).into_response())
        }
        "add" | "doadd" => {
            let title: Option<String> =
                sqlx::query_scalar("SELECT title FROM movieinfo WHERE imdbid = ? LIMIT 1")
                    .bind(&imdb)
                    .fetch_optional(db)
                    .await?;
            let (None, Some(title)) = (row, title) else {
                return Ok(Redirect::to("/mymovies").into_response());
            };
            let allowed = children(db, MOVIE_ROOT, MOVIE_WEBDL, true).await?;
            if action == "doadd" {
                sqlx::query("INSERT INTO user_movies (users_id, imdbid, categories, created_at) VALUES (?, ?, ?, NOW())")
                    .bind(user_id)
                    .bind(&imdb)
                    .bind(stored_categories(&input, &allowed))
                    .execute(db)
                    .await?;
                return Ok(Redirect::to(&from).into_response());
            }
            let categories = allowed
                .into_iter()
                .map(|(id, title)| CategoryChoice {
                    id,
                    title,
                    selected: false,
                })
                .collect();
            Ok(Html(form("Add Movie", title, "doadd", categories).render()?).into_response())
        }
        "edit" | "doedit" => {
            let Some(stored) = row else {
                return Ok(Redirect::to("/mymovies").into_response());
            };
            let allowed = children(db, MOVIE_ROOT, MOVIE_WEBDL, false).await?;
            if action == "doedit" {
                sqlx::query(
                    "UPDATE user_movies SET categories = ? WHERE users_id = ? AND imdbid = ?",
                )
                .bind(stored_categories(&input, &allowed))
                .bind(user_id)
                .bind(&imdb)
                .execute(db)
                .await?;
                return Ok(Redirect::to(&from).into_response());
            }
            let title: Option<String> =
                sqlx::query_scalar("SELECT title FROM movieinfo WHERE imdbid = ? LIMIT 1")
                    .bind(&imdb)
                    .fetch_optional(db)
                    .await?;
            let stored = stored.unwrap_or_default();
            let selected: Vec<&str> = stored.split('|').collect();
            let categories = allowed
                .into_iter()
                .map(|(id, title)| CategoryChoice {
                    selected: selected.contains(&id.to_string().as_str()),
                    id,
                    title,
                })
                .collect();
            Ok(Html(
                form(
                    "Edit Movie",
                    title.unwrap_or_default(),
                    "doedit",
                    categories,
                )
                .render()?,
            )
            .into_response())
        }
        "browse" => {
            let follows: Vec<(String, Option<String>)> =
                sqlx::query_as("SELECT imdbid, categories FROM user_movies WHERE users_id = ?")
                    .bind(user_id)
                    .fetch_all(db)
                    .await?;
            followed_page(
                &ctx,
                layout,
                &input,
                &signed_in,
                Followed {
                    path: "/mymovies",
                    heading: "My Movies",
                    description: "Browse Your Movies",
                    column: "imdbid",
                    categories: (MOVIE_ROOT, MOVIE_OTHER),
                },
                &follows,
            )
            .await
        }
        _ => {
            let categories = children(db, MOVIE_ROOT, MOVIE_WEBDL, false).await?;
            let rows: Vec<(String, Option<String>, Option<String>, Option<DateTime<Utc>>)> = sqlx::query_as(
                "SELECT um.imdbid, mi.title, um.categories, um.created_at FROM user_movies um
                 LEFT JOIN movieinfo mi ON mi.imdbid = um.imdbid WHERE um.users_id = ? ORDER BY mi.title",
            )
            .bind(user_id)
            .fetch_all(db)
            .await?;
            let items = rows
                .into_iter()
                .map(|(imdb, title, stored, at)| ListItem {
                    title: title.unwrap_or_else(|| imdb.clone()),
                    href: format!("/movie/{imdb}"),
                    categories: names_for(stored.as_deref().unwrap_or_default(), &categories),
                    added: added(at),
                    edit_url: format!("/mymovies?id=edit&imdb={}", encode_component(&imdb)),
                    delete_url: format!("/mymovies?id=delete&imdb={}", encode_component(&imdb)),
                })
                .collect();
            let page = MyListPage {
                layout: layout.titled("My Movies", LIST_KEYWORDS, "Manage Your Movies"),
                heading: "My Movies",
                description: "Movies you follow, and the categories you want from each.",
                icon: "fas fa-film",
                browse_url: "/mymovies?id=browse",
                rss_url: format!(
                    "/rss/mymovies?dl=1&i={user_id}&api_token={}",
                    signed_in.user.api_token
                ),
                items,
            };
            Ok(Html(page.render()?).into_response())
        }
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    fn cats() -> Vec<(i64, String)> {
        vec![(5040, "HD".to_owned()), (5030, "SD".to_owned())]
    }

    #[test]
    fn categories_store_and_read_like_php() {
        let input =
            Input::from_query("category%5B%5D=5040&category%5B%5D=9999&category%5B%5D=5030");
        assert_eq!(stored_categories(&input, &cats()), "5040|5030");
        assert_eq!(stored_categories(&Input::default(), &cats()), "NULL");
        assert_eq!(names_for("5040|5030", &cats()), "HD, SD");
        assert_eq!(names_for("NULL", &cats()), "");
    }

    #[test]
    fn episodes_parse_from_names_in_php_order() {
        assert_eq!(parse_episode("Show.S02E03.720p"), Some((2, 3)));
        assert_eq!(parse_episode("Show 4x12"), Some((4, 12)));
        assert_eq!(parse_episode("Show.Season 3 Episode 7"), Some((3, 7)));
        assert_eq!(parse_episode("Show.102.x264"), Some((1, 2)));
        assert_eq!(parse_episode("Show.2024.01.15"), Some((2024, 115)));
        assert_eq!(parse_episode("Show.Part.4"), Some((1, 4)));
        assert_eq!(parse_episode("Show.Ep 9"), Some((1, 9)));
        assert_eq!(parse_episode("Show.Movie"), None);
    }

    #[test]
    fn return_urls_stay_on_this_site() {
        let from = |value: &str| {
            local_return(
                &Input::from_query(&format!("from={}", encode_component(value))),
                "/x",
            )
        };
        assert_eq!(from("/series/4"), "/series/4");
        assert_eq!(from("//evil.example"), "/x");
        assert_eq!(from("https://evil.example"), "/x");
        assert_eq!(from("/a\\b"), "/x");
    }

    #[test]
    fn follows_bind_keys_and_parse_categories() {
        let follows = vec![
            ("12".to_owned(), Some("5040|5030".to_owned())),
            ("x' OR 1=1".to_owned(), Some("NULL".to_owned())),
        ];
        let (sql, binds) = followed_clause("videos_id", &follows);
        assert!(sql.contains("IN (5040,5030)"));
        assert!(!sql.contains("OR 1=1"));
        assert_eq!(binds.len(), 2);
        assert_eq!(followed_clause("videos_id", &[]).0, " AND (1=2)");
    }
}
