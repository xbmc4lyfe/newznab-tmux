//! RSS feeds: `RssController`, the queries in `Api\RSS` and the feed's HTML descriptions.

use std::path::Path;
use std::sync::Arc;

use anyhow::Result;
use chrono::{Duration, NaiveDateTime, Utc};
use md5::{Digest, Md5};
use nntmux_runtime::Ctx;
use sqlx::{AssertSqlSafe, QueryBuilder};

use crate::auth::{self, User};
use crate::cache;
use crate::caps::Server;
use crate::category;
use crate::db::row_json;
use crate::input::{Input, is_numeric, php_int};
use crate::json::{J, Style};
use crate::present_v1::{Namespace, Params, rss_json_refused, write_feed};
use crate::reply::{Reply, json_error};
use crate::request::ApiRequest;
use crate::rows::{Row, attach_partial_counts, is_empty};
use crate::settings::Settings;
use crate::usage::{now_string, rfc2822};
use crate::xml::XmlWriter;

const TV_ROOT: i64 = 5000;
const TV_OTHER: i64 = 5999;
const MOVIE_ROOT: i64 = 2000;
const MOVIE_OTHER: i64 = 2999;
const AUDIT_MAX_LENGTH: usize = 255;
/// `nntmux.cache_expiry_medium`, in seconds.
const QUERY_CACHE_TTL: u64 = 600;
const TRENDING_TTL: u64 = 3600;

#[derive(Clone, Copy, Debug, PartialEq, Eq)]
pub enum Feed {
    MyMovies,
    MyShows,
    Full,
    Cart,
    Category,
    TrendingMovies,
    TrendingShows,
}

const FEED_COLUMNS: &str =
    "r.searchname, r.guid, r.postdate, r.categories_id, r.size, r.totalpart, r.fromname,
    r.passwordstatus, r.grabs, r.comments, r.adddate, r.videos_id, r.tv_episodes_id,
    m.cover, m.imdbid, m.rating, m.plot, m.year, m.genre, m.director, m.actors,
    g.name AS group_name,
    CONCAT(cp.title, ' > ', c.title) AS category_name,
    mu.title AS mu_title, mu.url AS mu_url, mu.artist AS mu_artist,
    mu.publisher AS mu_publisher, mu.releasedate AS mu_releasedate,
    mu.review AS mu_review, mu.tracks AS mu_tracks, mu.cover AS mu_cover,
    mug.title AS mu_genre, co.title AS co_title, co.url AS co_url,
    co.publisher AS co_publisher, co.releasedate AS co_releasedate,
    co.review AS co_review, co.cover AS co_cover, cog.title AS co_genre,
    bo.cover AS bo_cover";

fn cart_no_cache(mut reply: Reply) -> Reply {
    reply.set_header(
        "cache-control",
        "max-age=0, must-revalidate, no-cache, no-store, private, s-maxage=0",
    );
    reply.set_header("pragma", "no-cache");
    reply.set_header("expires", "Thu, 01 Jan 1970 00:00:00 GMT");
    reply.set_header("cdn-cache-control", "no-store");
    reply.set_header("cloudflare-cdn-cache-control", "no-store");
    reply
}

/// `userCheck`: the token, the limit and the accounting. RSS counts its own way: strictly over the
/// limit, oldest times across all history, and the raw request URI in the audit row.
async fn user_check(
    ctx: &Ctx,
    request: &ApiRequest,
) -> Result<std::result::Result<(User, Params), Reply>> {
    let input = &request.input;
    if !input.has("api_token") {
        return Ok(Err(json_error(200, "Missing parameter (api_token)")));
    }
    let Some(user) = auth::find_verified(ctx, &input.string("api_token")).await? else {
        return Ok(Err(json_error(100, "")));
    };
    let since = (Utc::now() - Duration::days(1))
        .format("%Y-%m-%d %H:%M:%S")
        .to_string();
    let (used, grabs, oldest_api, oldest_grab): (
        i64,
        i64,
        Option<NaiveDateTime>,
        Option<NaiveDateTime>,
    ) = sqlx::query_as(
        "SELECT
            (SELECT COUNT(*) FROM user_requests WHERE users_id = ? AND timestamp > ?),
            (SELECT COUNT(*) FROM user_downloads WHERE users_id = ? AND timestamp > ?),
            (SELECT MIN(timestamp) FROM user_requests WHERE users_id = ?),
            (SELECT MIN(timestamp) FROM user_downloads WHERE users_id = ?)",
    )
    .bind(user.id)
    .bind(&since)
    .bind(user.id)
    .bind(&since)
    .bind(user.id)
    .bind(user.id)
    .fetch_one(&ctx.db)
    .await?;
    if user.disabled() {
        return Ok(Err(json_error(101, "")));
    }
    if used > user.api_requests {
        let max = user.api_requests;
        return Ok(Err(json_error(
            500,
            &format!("Request limit reached ({used}/{max})"),
        )));
    }

    let now = now_string();
    let uri: String = request
        .request_uri()
        .chars()
        .take(AUDIT_MAX_LENGTH)
        .collect();
    sqlx::query("INSERT INTO user_requests (users_id, request, timestamp) VALUES (?, ?, ?)")
        .bind(user.id)
        .bind(uri)
        .bind(&now)
        .execute(&ctx.db)
        .await?;
    match request.client_ip() {
        Some(ip) => {
            sqlx::query("UPDATE users SET apiaccess = ?, host = ? WHERE id = ?")
                .bind(&now)
                .bind(ip)
                .bind(user.id)
                .execute(&ctx.db)
                .await?;
        }
        None => {
            sqlx::query("UPDATE users SET apiaccess = ? WHERE id = ?")
                .bind(&now)
                .bind(user.id)
                .execute(&ctx.db)
                .await?;
        }
    }

    let params = Params {
        token: user.api_token.clone(),
        extended: true,
        delete_from_cart: input.str("del") == Some("1"),
        include_enclosure: input.str("dl") == Some("1"),
        api_limit: user.api_requests,
        requests: used,
        download_limit: user.download_requests,
        grabs,
        oldest_api: oldest_api.map(rfc2822).unwrap_or_default(),
        oldest_grab: oldest_grab.map(rfc2822).unwrap_or_default(),
    };
    Ok(Ok((user, params)))
}

/// `is_numeric($v) ? abs((int) $v) : $default`.
fn abs_numeric(input: &Input, key: &str, default: i64) -> i64 {
    match input.str(key) {
        Some(value) if is_numeric(value) => php_int(value).saturating_abs(),
        _ => default,
    }
}

async fn show_passwords_for_rss(ctx: &Ctx) -> Result<&'static str> {
    let allow: Option<bool> =
        sqlx::query_scalar("SELECT show_passworded_releases FROM ingestion_configurations LIMIT 1")
            .fetch_optional(&ctx.db)
            .await?;
    Ok(if allow.unwrap_or(false) {
        "BETWEEN 0 AND 1"
    } else {
        "= 0"
    })
}

async fn run(ctx: &Ctx, sql: String) -> Result<Vec<Row>> {
    let rows = sqlx::query(AssertSqlSafe(sql)).fetch_all(&ctx.db).await?;
    rows.iter()
        .map(|row| row_json(row).map(Row::from_json))
        .collect()
}

/// `Cache::get` then `Cache::put` around a query, keyed by its text.
async fn cached(ctx: &Ctx, settings: &Settings, sql: String) -> Result<Vec<Row>> {
    let key = format!("rss_rows:{}", hex::encode(Md5::digest(sql.as_bytes())));
    if let Some(rows) = cache::get::<Vec<Row>>(ctx, settings, &key).await {
        return Ok(rows);
    }
    let rows = run(ctx, sql).await?;
    cache::put(ctx, settings, &key, QUERY_CACHE_TTL, &rows).await;
    Ok(rows)
}

struct FeedQuery {
    categories: Vec<String>,
    show: i64,
    anidb: i64,
    air_date: i64,
    limit: i64,
    offset: i64,
}

/// `parseCommonRssParams`, for the feeds taking show, anidb and paging filters.
fn common_filters(input: &Input, settings: &Settings, categories: Vec<String>) -> FeedQuery {
    let positive = |key: &str| {
        let value = php_int(input.str(key).unwrap_or_default());
        if value == 0 { -1 } else { value }
    };
    let (mut show, mut anidb) = (-1, -1);
    if input.has("show") {
        show = positive("show");
    } else if input.has("anidb") {
        anidb = positive("anidb");
    }
    FeedQuery {
        categories,
        show,
        anidb,
        air_date: abs_numeric(input, "airdate", -1),
        // PHP answered 500 to any numeric limit, so nothing depends on larger pages; the cap stops
        // one request loading the whole table.
        limit: match input.str("limit") {
            Some(value) if is_numeric(value) => php_int(value).clamp(1, settings.limit_max()),
            _ => 100,
        },
        offset: abs_numeric(input, "num", 0),
    }
}

/// `getRss`. Laravel served uncategorized feeds through the search index when it could; the rows are
/// the same, in the same `postdate DESC` order.
async fn general_rows(
    ctx: &Ctx,
    settings: &Settings,
    user_id: i64,
    query: &FeedQuery,
) -> Result<Vec<Row>> {
    let first = query.categories.first().map_or(0, |id| php_int(id));
    let is_cart = first == -2;
    let cart_join = if is_cart {
        format!(
            "INNER JOIN users_releases ON users_releases.users_id = {user_id} AND users_releases.releases_id = r.id"
        )
    } else {
        String::new()
    };
    let cat_search = if !is_cart && first != -1 && !query.categories.is_empty() {
        category::sql_filter(&category::expand(ctx, &query.categories).await?)
    } else {
        String::new()
    };
    let cat_limit = if cat_search.is_empty() {
        format!("AND r.categories_id BETWEEN {TV_ROOT} AND {TV_OTHER}")
    } else {
        String::new()
    };
    let by_id = |column: &str, id: i64| {
        if id > 0 {
            format!("AND r.{column} = {id} {cat_limit}")
        } else {
            String::new()
        }
    };
    let air = if query.air_date > -1 {
        format!(
            "AND tve.firstaired >= DATE_SUB(CURDATE(), INTERVAL {} DAY)",
            query.air_date
        )
    } else {
        String::new()
    };
    let limit = format!(" LIMIT {} OFFSET {}", query.limit, query.offset);
    let sql = format!(
        "SELECT {FEED_COLUMNS}
         FROM releases r
         LEFT JOIN categories c ON c.id = r.categories_id
         INNER JOIN root_categories cp ON cp.id = c.root_categories_id
         LEFT JOIN usenet_groups g ON g.id = r.groups_id
         LEFT OUTER JOIN musicinfo mu ON mu.id = r.musicinfo_id
         LEFT OUTER JOIN genres mug ON mug.id = mu.genres_id
         LEFT OUTER JOIN consoleinfo co ON co.id = r.consoleinfo_id
         LEFT JOIN movieinfo m ON m.id = r.movieinfo_id
         LEFT OUTER JOIN genres cog ON cog.id = co.genres_id {cart_join}
         LEFT OUTER JOIN tv_episodes tve ON tve.id = r.tv_episodes_id
         LEFT OUTER JOIN bookinfo bo ON bo.id = r.bookinfo_id
         WHERE r.passwordstatus {passwords}
         {cat_search} {video} {anime} {air}
         ORDER BY postdate DESC {limit}",
        passwords = show_passwords_for_rss(ctx).await?,
        video = by_id("videos_id", query.show),
        anime = by_id("anidbid", query.anidb),
    );
    if is_cart {
        run(ctx, sql).await
    } else {
        cached(ctx, settings, sql).await
    }
}

fn excluded_sql(excluded: &[i64]) -> String {
    if excluded.is_empty() {
        return String::new();
    }
    let list: Vec<String> = excluded.iter().map(i64::to_string).collect();
    format!(" AND r.categories_id NOT IN ({})", list.join(","))
}

fn capped_limit(limit: i64) -> String {
    if limit == 0 {
        String::new()
    } else {
        format!(" LIMIT {} OFFSET 0", limit.min(100))
    }
}

async fn my_shows(
    ctx: &Ctx,
    settings: &Settings,
    user_id: i64,
    limit: i64,
    air_date: i64,
) -> Result<Vec<Row>> {
    let excluded = auth::category_exclusions(ctx, settings, user_id).await?;
    let air = if air_date > -1 {
        format!(" AND tve.firstaired >= DATE_SUB(CURDATE(), INTERVAL {air_date} DAY)")
    } else {
        String::new()
    };
    let sql = format!(
        "SELECT DISTINCT r.searchname, r.guid, r.postdate, r.categories_id, r.size, r.totalpart, r.fromname,
            r.passwordstatus, r.grabs, r.comments, r.adddate, r.videos_id, r.tv_episodes_id, v.id, v.title,
            g.name AS group_name, CONCAT(cp.title, '-', c.title) AS category_name
         FROM releases r
         INNER JOIN user_series us ON us.videos_id = r.videos_id AND us.users_id = {user_id}
         LEFT JOIN categories c ON c.id = r.categories_id
         INNER JOIN root_categories cp ON cp.id = c.root_categories_id
         LEFT JOIN usenet_groups g ON g.id = r.groups_id
         LEFT OUTER JOIN videos v ON v.id = r.videos_id
         LEFT OUTER JOIN tv_episodes tve ON tve.id = r.tv_episodes_id
         WHERE (us.categories IS NULL OR us.categories = '' OR us.categories = 'NULL'
                OR FIND_IN_SET(r.categories_id, REPLACE(us.categories,'|',',')) > 0){excluded}{air}
           AND r.categories_id BETWEEN {TV_ROOT} AND {TV_OTHER} AND r.passwordstatus {passwords}
         ORDER BY postdate DESC {limit}",
        excluded = excluded_sql(&excluded),
        passwords = show_passwords_for_rss(ctx).await?,
        limit = capped_limit(limit),
    );
    cached(ctx, settings, sql).await
}

async fn my_movies(ctx: &Ctx, settings: &Settings, user_id: i64, limit: i64) -> Result<Vec<Row>> {
    let excluded = auth::category_exclusions(ctx, settings, user_id).await?;
    let sql = format!(
        "SELECT DISTINCT r.searchname, r.guid, r.postdate, r.categories_id, r.size, r.totalpart, r.fromname,
            r.passwordstatus, r.grabs, r.comments, r.adddate, r.videos_id, r.tv_episodes_id, mi.title AS releasetitle,
            g.name AS group_name, CONCAT(cp.title, '-', c.title) AS category_name
         FROM releases r
         INNER JOIN user_movies um ON um.imdbid = r.imdbid AND um.users_id = {user_id}
         LEFT JOIN categories c ON c.id = r.categories_id
         INNER JOIN root_categories cp ON cp.id = c.root_categories_id
         LEFT JOIN usenet_groups g ON g.id = r.groups_id
         LEFT JOIN movieinfo mi ON mi.imdbid = r.imdbid
         WHERE r.imdbid IS NOT NULL
           AND (um.categories IS NULL OR um.categories = '' OR um.categories = 'NULL'
                OR FIND_IN_SET(r.categories_id, REPLACE(um.categories,'|',',')) > 0){excluded}
           AND r.categories_id BETWEEN {MOVIE_ROOT} AND {MOVIE_OTHER} AND r.passwordstatus {passwords}
         ORDER BY postdate DESC {limit}",
        excluded = excluded_sql(&excluded),
        passwords = show_passwords_for_rss(ctx).await?,
        limit = capped_limit(limit),
    );
    cached(ctx, settings, sql).await
}

fn hours_ago_48() -> String {
    (Utc::now() - Duration::hours(48))
        .format("%Y-%m-%d %H:%M:%S")
        .to_string()
}

/// The top 15 movies by downloads in 48 hours, each with its latest five releases.
async fn trending_movies(ctx: &Ctx, settings: &Settings) -> Result<Vec<Row>> {
    const KEY: &str = "rss_trending_movies_48h";
    if let Some(rows) = cache::get::<Vec<Row>>(ctx, settings, KEY).await {
        return Ok(rows);
    }
    let top: Vec<String> = sqlx::query_scalar(
        "SELECT m.imdbid FROM movieinfo m
         INNER JOIN releases r ON m.imdbid = r.imdbid
         LEFT JOIN user_downloads ud ON r.id = ud.releases_id
         WHERE m.title != '' AND m.imdbid IS NOT NULL AND m.imdbid != '' AND ud.timestamp >= ?
         GROUP BY m.imdbid
         HAVING COUNT(DISTINCT ud.id) > 0
         ORDER BY COUNT(DISTINCT ud.id) DESC
         LIMIT 15",
    )
    .bind(hours_ago_48())
    .fetch_all(&ctx.db)
    .await?;
    let mut rows = Vec::new();
    if !top.is_empty() {
        let mut query = QueryBuilder::new(
            "SELECT * FROM (
                SELECT r.searchname, r.guid, r.postdate, r.categories_id, r.size,
                    r.totalpart, r.fromname, r.passwordstatus, r.grabs, r.comments,
                    r.adddate, r.imdbid,
                    m.title, m.year, m.rating, m.plot, m.genre, m.cover, m.tmdbid, m.traktid,
                    g.name AS group_name,
                    CONCAT(cp.title, ' > ', c.title) AS category_name,
                    ROW_NUMBER() OVER (PARTITION BY r.imdbid ORDER BY r.postdate DESC) as rn
                FROM releases r
                INNER JOIN movieinfo m ON m.imdbid = r.imdbid
                LEFT JOIN categories c ON c.id = r.categories_id
                INNER JOIN root_categories cp ON cp.id = c.root_categories_id
                LEFT JOIN usenet_groups g ON g.id = r.groups_id
                WHERE r.imdbid IN (",
        );
        let mut list = query.separated(", ");
        for id in &top {
            list.push_bind(id.as_str());
        }
        query.push(") AND r.passwordstatus ");
        query.push(show_passwords_for_rss(ctx).await?);
        query.push(") AS ranked WHERE rn <= 5 ORDER BY FIELD(imdbid");
        for id in &top {
            query.push(", ");
            query.push_bind(id.as_str());
        }
        query.push("), postdate DESC");
        for row in query.build().fetch_all(&ctx.db).await? {
            rows.push(Row::from_json(row_json(&row)?));
        }
    }
    cache::put(ctx, settings, KEY, TRENDING_TTL, &rows).await;
    Ok(rows)
}

/// The top 15 shows by downloads in 48 hours, each with its latest five releases.
async fn trending_shows(ctx: &Ctx, settings: &Settings) -> Result<Vec<Row>> {
    const KEY: &str = "rss_trending_shows_48h";
    if let Some(rows) = cache::get::<Vec<Row>>(ctx, settings, KEY).await {
        return Ok(rows);
    }
    let top: Vec<u32> = sqlx::query_scalar(
        "SELECT v.id FROM videos v
         INNER JOIN releases r ON v.id = r.videos_id
         LEFT JOIN user_downloads ud ON r.id = ud.releases_id
         WHERE v.type = 0 AND v.title != '' AND ud.timestamp >= ?
         GROUP BY v.id
         HAVING COUNT(DISTINCT ud.id) > 0
         ORDER BY COUNT(DISTINCT ud.id) DESC
         LIMIT 15",
    )
    .bind(hours_ago_48())
    .fetch_all(&ctx.db)
    .await?;
    let rows = if top.is_empty() {
        Vec::new()
    } else {
        let ids = top.iter().map(u32::to_string).collect::<Vec<_>>().join(",");
        run(
            ctx,
            format!(
                "SELECT * FROM (
                    SELECT r.searchname, r.guid, r.postdate, r.categories_id, r.size,
                        r.totalpart, r.fromname, r.passwordstatus, r.grabs, r.comments,
                        r.adddate, r.videos_id, r.tv_episodes_id,
                        v.title, v.started, v.tvdb, v.tvmaze, v.trakt, v.tmdb, v.countries_id,
                        ti.summary, ti.image,
                        g.name AS group_name,
                        CONCAT(cp.title, ' > ', c.title) AS category_name,
                        ROW_NUMBER() OVER (PARTITION BY r.videos_id ORDER BY r.postdate DESC) as rn
                    FROM releases r
                    INNER JOIN videos v ON v.id = r.videos_id
                    INNER JOIN tv_info ti ON ti.videos_id = v.id
                    LEFT JOIN categories c ON c.id = r.categories_id
                    INNER JOIN root_categories cp ON cp.id = c.root_categories_id
                    LEFT JOIN usenet_groups g ON g.id = r.groups_id
                    WHERE r.videos_id IN ({ids}) AND v.type = 0 AND r.passwordstatus {passwords}
                ) AS ranked
                WHERE rn <= 5
                ORDER BY FIELD(videos_id, {ids}), postdate DESC",
                passwords = show_passwords_for_rss(ctx).await?,
            ),
        )
        .await?
    };
    cache::put(ctx, settings, KEY, TRENDING_TTL, &rows).await;
    Ok(rows)
}

/// `human_filesize`: the unit comes from how many digits the number has, not its size.
fn human_filesize(bytes: i64) -> String {
    const UNITS: [&str; 9] = ["B", "kB", "MB", "GB", "TB", "PB", "EB", "ZB", "YB"];
    let digits = bytes.to_string().len();
    let factor = (digits - 1) / 3;
    let value = bytes as f64 / 1024f64.powi(i32::try_from(factor).unwrap_or(0));
    format!("{:.0}{}", value, UNITS.get(factor).copied().unwrap_or(""))
}

/// `resolveImageAssetFilename`.
pub fn resolve_cover(roots: &[std::path::PathBuf], dir: &str, basename: &str) -> Option<String> {
    let safe = |name: &str| {
        name.chars()
            .next()
            .is_some_and(|c| c.is_ascii_alphanumeric())
            && name
                .chars()
                .all(|c| c.is_ascii_alphanumeric() || c == '_' || c == '-')
    };
    if !safe(basename) {
        return None;
    }
    for extension in ["webp", "jpg", "jpeg"] {
        for root in roots {
            let file = format!("{basename}.{extension}");
            if is_file(&root.join(dir).join(&file)) {
                return Some(file);
            }
        }
    }
    None
}

fn is_file(path: &Path) -> bool {
    path.is_file()
}

/// `writeRssCdata`: an HTML description. The music, console and movie sections in the PHP need
/// columns these queries don't select or a strict comparison they fail, so no feed reaches them.
fn write_description(xml: &mut XmlWriter, row: &Row, url: &str, roots: &[std::path::PathBuf]) {
    let mut html = String::from("\n\t<div>\n");
    let cover = [
        ("cover", "movies", "imdbid"),
        ("mu_cover", "music", "musicinfo_id"),
        ("co_cover", "console", "consoleinfo_id"),
        ("bo_cover", "book", "bookinfo_id"),
    ]
    .into_iter()
    .find(|(column, _, _)| !is_empty(row.get(column)));
    let name = row.text("searchname");
    if let Some((_, dir, id_column)) = cover {
        let suffix = if dir == "movies" { "-cover" } else { "" };
        let basename = format!("{}{suffix}", row.text(id_column));
        let file =
            resolve_cover(roots, dir, &basename).unwrap_or_else(|| "no-cover.jpg".to_owned());
        html.push_str(&format!(
            "\t<img style=\"margin-left:10px;margin-bottom:10px;float:right;\" src=\"{url}/covers/{dir}/{file}\" width=\"120\" alt=\"{name}\" />\n"
        ));
    }
    let guid = row.text("guid");
    let category = row.text("category_name");
    let group = row.text("group_name");
    let password = match row.get("passwordstatus") {
        None | Some(J::Int(0)) => "None",
        Some(J::Int(1)) => "Possibly Passworded",
        Some(J::Int(2)) => "Probably not viable",
        Some(J::Int(10)) => "Passworded",
        Some(_) => "Unknown",
    };
    html.push_str(&format!(
        "\t<li>ID: <a href=\"{url}/details/{guid}\">{guid}</a></li>\n\
         \t<li>Name: {name}</li>\n\
         \t<li>Size: {size}</li>\n\
         \t<li>Category: <a href=\"{url}/browse/{category}\">{category}</a></li>\n\
         \t<li>Group: <a href=\"{url}/browse/group?g={group}\">{group}</a></li>\n\
         \t<li>Poster: {poster}</li>\n\
         \t<li>Posted: {posted}</li>\n\
         \t<li>Password: {password}</li>\n\t</div>",
        size = human_filesize(row.int("size")),
        poster = row.text("fromname"),
        posted = row.text("postdate"),
    ));
    xml.start("description");
    xml.cdata(&html);
    xml.end();
}

pub async fn feed(
    ctx: &Ctx,
    settings: &Arc<Settings>,
    request: &ApiRequest,
    feed: Feed,
) -> Result<Reply> {
    let input = &request.input;
    let category_input = crate::getnzb::scalar_id(input);
    if feed == Feed::Category && category_input.is_empty() {
        return Ok(Reply::json(
            403,
            &J::obj([("error", J::str("Category ID is missing"))]),
            Style::Plain,
        ));
    }
    let (user, params) = match user_check(ctx, request).await? {
        Ok(found) => found,
        Err(reply) if feed == Feed::Cart => return Ok(cart_no_cache(reply)),
        Err(reply) => return Ok(reply),
    };
    let mut rows = match feed {
        Feed::MyMovies => my_movies(ctx, settings, user.id, abs_numeric(input, "num", 0)).await?,
        Feed::MyShows => {
            my_shows(
                ctx,
                settings,
                user.id,
                abs_numeric(input, "num", 0),
                abs_numeric(input, "airdate", -1),
            )
            .await?
        }
        Feed::Full => {
            general_rows(
                ctx,
                settings,
                user.id,
                &common_filters(input, settings, vec!["0".to_owned()]),
            )
            .await?
        }
        Feed::Cart => {
            general_rows(
                ctx,
                settings,
                user.id,
                &common_filters(input, settings, vec!["-2".to_owned()]),
            )
            .await?
        }
        Feed::Category => {
            let ids: Vec<String> = category_input
                .split(',')
                .map(|id| id.trim().to_owned())
                .filter(|id| {
                    let digits = id.strip_prefix('-').unwrap_or(id);
                    !digits.is_empty() && digits.bytes().all(|byte| byte.is_ascii_digit())
                })
                .collect();
            if ids.is_empty() {
                return Ok(Reply::json(
                    403,
                    &J::obj([("error", J::str("Category ID is missing"))]),
                    Style::Plain,
                ));
            }
            general_rows(
                ctx,
                settings,
                user.id,
                &common_filters(input, settings, ids),
            )
            .await?
        }
        Feed::TrendingMovies => trending_movies(ctx, settings).await?,
        Feed::TrendingShows => trending_shows(ctx, settings).await?,
    };
    attach_partial_counts(ctx, &mut rows).await;
    if input.str("o") == Some("json") {
        let reply = rss_json_refused();
        return Ok(if feed == Feed::Cart {
            cart_no_cache(reply)
        } else {
            reply
        });
    }
    let server = Server::load(ctx, settings, request.base_url()).await?;
    let roots = settings.cover_roots.clone();
    let body = write_feed(
        &rows,
        &params,
        &server,
        0,
        Namespace::Nntmux,
        &|xml, row, url| {
            write_description(xml, row, url, &roots);
        },
    );
    let reply = Reply::new(200, "text/xml; charset=utf-8", body);
    Ok(if feed == Feed::Cart {
        cart_no_cache(reply)
    } else {
        reply
    })
}

#[cfg(test)]
mod tests {
    use super::human_filesize;

    #[test]
    fn sizes_format_like_php() {
        for (bytes, expected) in [
            (5_368_709_120, "5GB"),
            (734_003_200, "700MB"),
            (1000, "1kB"),
            (999, "999B"),
            (0, "0B"),
            (1_048_576, "1MB"),
        ] {
            assert_eq!(human_filesize(bytes), expected);
        }
    }
}
