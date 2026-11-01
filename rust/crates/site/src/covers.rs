//! The cover pages: movies, music, console, games and books as cards, adult as a release list,
//! one movie's page, its trailer, and the trending lists.

use anyhow::Result;
use askama::Template;
use axum::extract::{Path, State};
use axum::http::StatusCode;
use axum::middleware::from_fn;
use axum::response::{Html, IntoResponse, Redirect};
use axum::routing::{get, post};
use axum::{Json, Router};
use chrono::NaiveDateTime;
use nntmux_core::parsed_or;
use nntmux_runtime::Ctx;
use nntmux_webkit::config::encode_component;
use nntmux_webkit::{CurrentUser, Input, Layout, Pager, Session, WebResult, guard, user};
use serde_json::json;
use sqlx::{MySqlPool, Row};

use crate::browse::clearance;
use crate::releases::{
    BrowseFilter, ReleaseList, browse_count, browse_rows, cover_url, expand_categories, local_time,
    order_or_default, password_limit, per_page,
};

const NO_COVER: &str = "/assets/images/no-cover.png";
const RELEASES_PER_CARD: usize = 5;

pub fn router() -> Router<Ctx> {
    Router::new()
        .route("/Movies", get(movies).post(movies))
        .route("/Movies/{id}", get(movies_in).post(movies_in))
        .route("/movie", get(movies).post(movies))
        .route("/movie/{imdbid}", get(movie).post(movie))
        .route("/movietrailers", get(trailer).post(trailer))
        .route(
            "/trending-movies",
            get(trending_movies).post(trending_movies),
        )
        .route("/movies/update-layout", post(update_layout))
        .route("/Audio", get(audio).post(audio))
        .route("/Audio/{id}", get(audio_in).post(audio_in))
        .route("/Console", get(console).post(console))
        .route("/Console/{id}", get(console_in).post(console_in))
        .route("/Games", get(games).post(games))
        .route("/Books", get(books).post(books))
        .route("/Books/{id}", get(books_in).post(books_in))
        .route("/XXX", get(adult).post(adult))
        .route("/XXX/{id}", get(adult_in).post(adult_in))
        .route_layer(from_fn(clearance))
        .route_layer(from_fn(guard::two_factor))
        .route_layer(from_fn(guard::verified))
        .route_layer(from_fn(guard::authenticated))
}

fn per_cover_page() -> u64 {
    parsed_or("ITEMS_PER_COVER_PAGE", 20).unwrap_or(20)
}

/// One filter field. `column` is a literal from this file.
#[derive(Clone, Copy)]
enum Match {
    Like(&'static str),
    Equal(&'static str),
    /// `rating=7` means any 7.x.
    RatingPrefix(&'static str),
    Year(&'static str),
}

struct KindConfig {
    title: &'static str,
    description: &'static str,
    icon: &'static str,
    keywords: &'static str,
    path: &'static str,
    list_url: &'static str,
    root: i64,
    table: &'static str,
    /// The release column joined to the info row's key.
    release_key: &'static str,
    key: &'static str,
    cover_kind: &'static str,
    cover_suffix: &'static str,
    /// Conditions a row needs to be shown.
    required: &'static str,
    filters: &'static [(&'static str, &'static str, Match)],
    /// `(column, label, link param)`. A link param makes the value comma-separated links.
    meta: &'static [(&'static str, &'static str, Option<&'static str>)],
    sorts: &'static [(&'static str, &'static str, &'static str)],
    genre_type: Option<i64>,
    genre_label: &'static str,
    link_movie: bool,
}

const MOVIES: KindConfig = KindConfig {
    title: "Browse Movies",
    description: "Browse for Movies",
    icon: "fas fa-film",
    keywords: "browse,nzb,description,details",
    path: "/Movies",
    list_url: "/browse/Movies",
    root: 2000,
    table: "movieinfo",
    release_key: "imdbid",
    key: "imdbid",
    cover_kind: "movies",
    cover_suffix: "-cover",
    required: "m.title <> '' AND m.imdbid IS NOT NULL AND m.imdbid <> ''",
    filters: &[
        ("title", "Title", Match::Like("title")),
        ("actors", "Actors", Match::Like("actors")),
        ("director", "Director", Match::Like("director")),
        ("genre", "Genre", Match::Like("genre")),
        ("rating", "Rating", Match::RatingPrefix("rating")),
        ("year", "Year", Match::Year("year")),
        ("imdb", "IMDB id", Match::Equal("imdbid")),
    ],
    meta: &[
        ("year", "Year", None),
        ("rating", "Rating", None),
        ("genre", "Genre", Some("genre")),
        ("director", "Director", Some("director")),
        ("actors", "Cast", Some("actors")),
        ("plot", "Plot", None),
    ],
    sorts: &[
        ("posted_desc", "Latest", "latest DESC"),
        ("title_asc", "Title A-Z", "m.title ASC"),
        ("title_desc", "Title Z-A", "m.title DESC"),
        ("year_desc", "Year (New)", "m.year DESC"),
        ("year_asc", "Year (Old)", "m.year ASC"),
        ("rating_desc", "Rating (High)", "m.rating DESC"),
        ("rating_asc", "Rating (Low)", "m.rating ASC"),
    ],
    genre_type: None,
    genre_label: "",
    link_movie: true,
};

const MUSIC: KindConfig = KindConfig {
    title: "Browse Albums",
    description: "Browse for Albums",
    icon: "fas fa-music",
    keywords: "browse,nzb,albums,description,details",
    path: "/Audio",
    list_url: "/browse/Audio",
    root: 3000,
    table: "musicinfo",
    release_key: "musicinfo_id",
    key: "id",
    cover_kind: "music",
    cover_suffix: "",
    required: "m.title <> '' AND m.cover = 1",
    filters: &[
        ("artist", "Artist", Match::Like("artist")),
        ("title", "Title", Match::Like("title")),
        ("year", "Year", Match::Year("year")),
    ],
    meta: &[
        ("artist", "Artist", None),
        ("year", "Year", None),
        ("publisher", "Publisher", None),
    ],
    sorts: &[
        ("posted_desc", "Latest", "latest DESC"),
        ("artist_asc", "Artist A-Z", "m.artist ASC"),
        ("artist_desc", "Artist Z-A", "m.artist DESC"),
        ("year_desc", "Year (New)", "m.year DESC"),
        ("year_asc", "Year (Old)", "m.year ASC"),
    ],
    genre_type: Some(3000),
    genre_label: "Genre",
    link_movie: false,
};

const CONSOLE: KindConfig = KindConfig {
    title: "Browse Console",
    description: "Browse for Console",
    icon: "fas fa-gamepad",
    keywords: "browse,nzb,console,games,description,details",
    path: "/Console",
    list_url: "/browse/Console",
    root: 1000,
    table: "consoleinfo",
    release_key: "consoleinfo_id",
    key: "id",
    cover_kind: "console",
    cover_suffix: "",
    required: "m.title <> '' AND m.cover = 1",
    filters: &[
        ("title", "Title", Match::Like("title")),
        ("platform", "Platform", Match::Like("platform")),
    ],
    meta: &[
        ("platform", "Platform", None),
        ("publisher", "Publisher", None),
        ("esrb", "Rating", None),
        ("releasedate", "Released", None),
    ],
    sorts: &[
        ("posted_desc", "Latest", "latest DESC"),
        ("title_asc", "Title A-Z", "m.title ASC"),
        ("title_desc", "Title Z-A", "m.title DESC"),
        ("platform_asc", "Platform A-Z", "m.platform ASC"),
    ],
    genre_type: Some(1000),
    genre_label: "Genre",
    link_movie: false,
};

const GAMES: KindConfig = KindConfig {
    title: "Browse Games",
    description: "Browse for Games",
    icon: "fas fa-gamepad",
    keywords: "browse,nzb,games,description,details",
    path: "/Games",
    list_url: "/browse/PC/Games",
    root: 4050,
    table: "gamesinfo",
    release_key: "gamesinfo_id",
    key: "id",
    cover_kind: "games",
    cover_suffix: "",
    required: "m.title <> '' AND m.cover = 1",
    filters: &[("title", "Title", Match::Like("title"))],
    meta: &[
        ("publisher", "Publisher", None),
        ("esrb", "Rating", None),
        ("releasedate", "Released", None),
    ],
    sorts: &[
        ("posted_desc", "Latest", "latest DESC"),
        ("title_asc", "Title A-Z", "m.title ASC"),
        ("title_desc", "Title Z-A", "m.title DESC"),
        ("releasedate_desc", "Released (New)", "m.releasedate DESC"),
    ],
    genre_type: Some(4000),
    genre_label: "Genre",
    link_movie: false,
};

const BOOKS: KindConfig = KindConfig {
    title: "Browse Books",
    description: "Browse for Books",
    icon: "fas fa-book",
    keywords: "browse,nzb,books,description,details",
    path: "/Books",
    list_url: "/browse/Books",
    root: 7000,
    table: "bookinfo",
    release_key: "bookinfo_id",
    key: "id",
    cover_kind: "book",
    cover_suffix: "",
    required: "m.title <> '' AND m.cover = 1",
    filters: &[
        ("author", "Author", Match::Like("author")),
        ("title", "Title", Match::Like("title")),
    ],
    meta: &[
        ("author", "Author", None),
        ("publisher", "Publisher", None),
        ("publishdate", "Published", None),
        ("overview", "Overview", None),
    ],
    sorts: &[
        ("posted_desc", "Latest", "latest DESC"),
        ("title_asc", "Title A-Z", "m.title ASC"),
        ("title_desc", "Title Z-A", "m.title DESC"),
        ("author_asc", "Author A-Z", "m.author ASC"),
    ],
    genre_type: None,
    genre_label: "",
    link_movie: false,
};

struct FilterView {
    name: &'static str,
    label: &'static str,
    value: String,
    options: Vec<(String, String)>,
}

struct SortLink {
    label: &'static str,
    url: String,
    current: bool,
}

struct MetaView {
    label: &'static str,
    value: String,
    links: Vec<(String, String)>,
}

struct CardRelease {
    guid: String,
    searchname: String,
    size_label: String,
    posted: String,
}

struct Card {
    title: String,
    href: Option<String>,
    cover: String,
    meta: Vec<MetaView>,
    releases: Vec<CardRelease>,
    more: i64,
}

#[derive(Template)]
#[template(path = "covers/index.html")]
struct CoverPage {
    layout: Layout,
    page_title: &'static str,
    page_description: &'static str,
    icon: &'static str,
    path: String,
    list_url: String,
    filters: Vec<FilterView>,
    sorts: Vec<SortLink>,
    cards: Vec<Card>,
    pager: Pager,
}

/// Rows of text columns. `sql` is built from literals in this file; every value is bound.
async fn text_rows(
    db: &MySqlPool,
    sql: String,
    binds: &[String],
    width: usize,
) -> Result<Vec<Vec<String>>> {
    let mut query = sqlx::query(sqlx::AssertSqlSafe(sql));
    for bind in binds {
        query = query.bind(bind);
    }
    let rows = query.fetch_all(db).await?;
    Ok(rows
        .iter()
        .map(|row| {
            (0..width)
                .map(|index| {
                    row.try_get::<Option<String>, _>(index)
                        .ok()
                        .flatten()
                        .unwrap_or_default()
                })
                .collect()
        })
        .collect())
}

fn id_list(ids: &[i64]) -> String {
    ids.iter().map(i64::to_string).collect::<Vec<_>>().join(",")
}

fn year_ok(value: &str) -> bool {
    value.len() == 4
        && (value.starts_with("19") || value.starts_with("20"))
        && value.bytes().all(|byte| byte.is_ascii_digit())
}

/// Conditions from the filter form, and the values to bind.
fn filter_sql(kind: &KindConfig, input: &Input) -> (String, Vec<String>) {
    let mut clause = String::new();
    let mut binds = Vec::new();
    for (name, _, how) in kind.filters {
        let Some(value) = input.get(name) else {
            continue;
        };
        match how {
            Match::Like(column) => {
                clause.push_str(&format!(" AND m.{column} LIKE ?"));
                binds.push(format!("%{value}%"));
            }
            Match::Equal(column) => {
                clause.push_str(&format!(" AND m.{column} = ?"));
                binds.push(value);
            }
            Match::RatingPrefix(column) => {
                clause.push_str(&format!(" AND m.{column} LIKE ?"));
                binds.push(format!("%{value}.%"));
            }
            Match::Year(column) => {
                if year_ok(&value) {
                    clause.push_str(&format!(" AND m.{column} = ?"));
                    binds.push(value);
                }
            }
        }
    }
    if kind.genre_type.is_some()
        && let Some(genre) = input
            .get("genre")
            .filter(|genre| genre.bytes().all(|byte| byte.is_ascii_digit()))
    {
        clause.push_str(" AND m.genres_id = ?");
        binds.push(genre);
    }
    (clause, binds)
}

async fn category_clause(db: &MySqlPool, category: i64, excluded: &[i64]) -> Result<String> {
    let mut clause = format!(" AND r.passwordstatus <= {}", password_limit(db).await?);
    if let Some(ids) = expand_categories(db, &[category]).await? {
        clause.push_str(&format!(" AND r.categories_id IN ({})", id_list(&ids)));
    }
    if !excluded.is_empty() {
        clause.push_str(&format!(
            " AND r.categories_id NOT IN ({})",
            id_list(excluded)
        ));
    }
    Ok(clause)
}

fn with_sort(path: &str, query: &[(String, String)], sort: &str) -> String {
    let mut url = format!("{path}?");
    for (key, value) in query.iter().filter(|(key, _)| key != "ob") {
        url.push_str(&format!(
            "{}={}&",
            encode_component(key),
            encode_component(value)
        ));
    }
    url.push_str(&format!("ob={sort}"));
    url
}

fn names_to_links(value: &str, path: &str, param: &str) -> Vec<(String, String)> {
    value
        .split(", ")
        .map(str::trim)
        .filter(|name| !name.is_empty())
        .take(8)
        .map(|name| {
            (
                name.to_owned(),
                format!("{path}?{param}={}", encode_component(name)),
            )
        })
        .collect()
}

fn card_cover(kind: &KindConfig, key: &str) -> String {
    cover_url(kind.cover_kind, &format!("{key}{}", kind.cover_suffix))
        .unwrap_or_else(|| NO_COVER.to_owned())
}

fn card_from(
    kind: &KindConfig,
    key: &str,
    title: String,
    values: &[String],
    releases: Vec<CardRelease>,
    more: i64,
) -> Card {
    let meta = kind
        .meta
        .iter()
        .zip(values)
        .filter(|(_, value)| !value.is_empty())
        .map(|((_, label, param), value)| match param {
            Some(param) => MetaView {
                label,
                value: String::new(),
                links: names_to_links(value, kind.path, param),
            },
            None => MetaView {
                label,
                value: value.split(" 00:00:00").next().unwrap_or(value).to_owned(),
                links: Vec::new(),
            },
        })
        .collect();
    Card {
        title,
        href: kind.link_movie.then(|| format!("/movie/{key}")),
        cover: card_cover(kind, key),
        meta,
        releases,
        more,
    }
}

async fn releases_for(
    db: &MySqlPool,
    kind: &KindConfig,
    key: &str,
    clause: &str,
    limit: Option<usize>,
    timezone: Option<&str>,
) -> Result<(Vec<CardRelease>, i64)> {
    let limit_sql = limit
        .map(|limit| format!(" LIMIT {limit}"))
        .unwrap_or_default();
    let sql = format!(
        "SELECT r.guid, r.searchname, CAST(r.size AS SIGNED), r.postdate FROM releases r
         WHERE r.{} = ? {clause} ORDER BY r.postdate DESC{limit_sql}",
        kind.release_key
    );
    let rows: Vec<(String, String, i64, Option<NaiveDateTime>)> =
        sqlx::query_as(sqlx::AssertSqlSafe(sql))
            .bind(key)
            .fetch_all(db)
            .await?;
    let total: i64 = sqlx::query_scalar(sqlx::AssertSqlSafe(format!(
        "SELECT COUNT(*) FROM releases r WHERE r.{} = ? {clause}",
        kind.release_key
    )))
    .bind(key)
    .fetch_one(db)
    .await?;
    let shown = rows.len() as i64;
    let releases = rows
        .into_iter()
        .map(|(guid, searchname, size, posted)| CardRelease {
            guid,
            searchname,
            size_label: format!("{:.2} GB", size as f64 / 1_073_741_824.0),
            posted: posted
                .map(|at| local_time(at, timezone, "%b %d, %Y"))
                .unwrap_or_default(),
        })
        .collect();
    Ok((releases, total - shown))
}

async fn cover_page(
    ctx: &Ctx,
    layout: Layout,
    input: &Input,
    signed_in: &nntmux_webkit::auth::Auth,
    kind: &KindConfig,
    sub: Option<String>,
) -> WebResult {
    let db = &ctx.db;
    // A movie looked up by id spans every category, as PHP's `has('imdb') ? -1` did.
    let mut category = if kind.root == 2000 && input.has("imdb") {
        -1
    } else {
        input.int("t", kind.root)
    };
    let mut path = kind.path.to_owned();
    let mut list_url = kind.list_url.to_owned();
    if let Some(sub) = &sub {
        let found: Option<i64> = sqlx::query_scalar(
            "SELECT CAST(id AS SIGNED) FROM categories WHERE title = ? AND root_categories_id = ?",
        )
        .bind(sub)
        .bind(kind.root)
        .fetch_optional(db)
        .await?;
        if let Some(found) = found {
            category = found;
            path = format!("{}/{}", kind.path, crate::releases::path_segment(sub));
            list_url = format!("{}/{}", kind.list_url, crate::releases::path_segment(sub));
        }
    }
    let excluded = user::category_exclusions(db, signed_in.user.id, &signed_in.access).await?;
    let release_clause = category_clause(db, category, &excluded).await?;
    let (filter_clause, binds) = filter_sql(kind, input);
    let from = format!(
        "{} m INNER JOIN releases r ON r.{} = m.{} WHERE {}{filter_clause}{release_clause}",
        kind.table, kind.release_key, kind.key, kind.required
    );
    let mut count = sqlx::query_scalar::<_, i64>(sqlx::AssertSqlSafe(format!(
        "SELECT COUNT(DISTINCT m.{}) FROM {from}",
        kind.key
    )));
    for bind in &binds {
        count = count.bind(bind);
    }
    let total = count.fetch_one(db).await?.max(0) as u64;
    let query = input.query_pairs();
    let pager = Pager::new(&path, &query, total, per_cover_page(), input.page());

    let requested = input.str("ob");
    let current = kind
        .sorts
        .iter()
        .find(|(key, _, _)| *key == requested)
        .unwrap_or(&kind.sorts[0]);
    let sorts = kind
        .sorts
        .iter()
        .map(|(key, label, _)| SortLink {
            label,
            url: with_sort(&path, &query, key),
            current: *key == current.0,
        })
        .collect();

    let meta_columns: Vec<String> = kind
        .meta
        .iter()
        .map(|(column, _, _)| format!("CAST(m.`{column}` AS CHAR)"))
        .collect();
    let group_columns: Vec<String> = kind
        .meta
        .iter()
        .map(|(column, _, _)| format!("m.`{column}`"))
        .collect();
    let sql = format!(
        "SELECT CAST(m.{key} AS CHAR), m.title, {meta}, MAX(r.postdate) AS latest
         FROM {from} GROUP BY m.{key}, m.title, {group} ORDER BY {order} LIMIT {limit} OFFSET {offset}",
        key = kind.key,
        meta = meta_columns.join(", "),
        group = group_columns.join(", "),
        order = current.2,
        limit = pager.per_page,
        offset = pager.offset(),
    );
    let mut cards = Vec::new();
    if pager.offset() < total {
        let width = 2 + kind.meta.len();
        for row in text_rows(db, sql, &binds, width).await? {
            let (releases, more) = releases_for(
                db,
                kind,
                &row[0],
                &release_clause,
                Some(RELEASES_PER_CARD),
                signed_in.user.timezone.as_deref(),
            )
            .await?;
            cards.push(card_from(
                kind,
                &row[0],
                row[1].clone(),
                &row[2..],
                releases,
                more,
            ));
        }
    }

    let mut filters: Vec<FilterView> = kind
        .filters
        .iter()
        .map(|(name, label, _)| FilterView {
            name,
            label,
            value: input.str(name),
            options: Vec::new(),
        })
        .collect();
    if let Some(genre_type) = kind.genre_type {
        let genres: Vec<(i64, String)> = sqlx::query_as("SELECT CAST(id AS SIGNED), title FROM genres WHERE type = ? AND disabled = 0 ORDER BY title")
            .bind(genre_type)
            .fetch_all(db)
            .await?;
        filters.push(FilterView {
            name: "genre",
            label: kind.genre_label,
            value: input.str("genre"),
            options: genres
                .into_iter()
                .map(|(id, title)| (id.to_string(), title))
                .collect(),
        });
    }
    let page = CoverPage {
        layout: layout.titled(kind.title, kind.keywords, kind.description),
        page_title: kind.title,
        page_description: kind.description,
        icon: kind.icon,
        path,
        list_url,
        filters,
        sorts,
        cards,
        pager,
    };
    Ok(Html(page.render()?).into_response())
}

macro_rules! cover_routes {
    ($plain:ident, $sub:ident, $kind:ident) => {
        async fn $plain(
            State(ctx): State<Ctx>,
            layout: Layout,
            input: Input,
            CurrentUser(signed_in): CurrentUser,
        ) -> WebResult {
            cover_page(&ctx, layout, &input, &signed_in, &$kind, None).await
        }

        async fn $sub(
            State(ctx): State<Ctx>,
            layout: Layout,
            input: Input,
            Path(id): Path<String>,
            CurrentUser(signed_in): CurrentUser,
        ) -> WebResult {
            cover_page(&ctx, layout, &input, &signed_in, &$kind, Some(id)).await
        }
    };
}

cover_routes!(movies, movies_in, MOVIES);
cover_routes!(audio, audio_in, MUSIC);
cover_routes!(console, console_in, CONSOLE);
cover_routes!(books, books_in, BOOKS);

async fn games(
    State(ctx): State<Ctx>,
    layout: Layout,
    input: Input,
    CurrentUser(signed_in): CurrentUser,
) -> WebResult {
    cover_page(&ctx, layout, &input, &signed_in, &GAMES, None).await
}

#[derive(Template)]
#[template(path = "browse/index.html")]
struct AdultPage {
    layout: Layout,
    browse_title: String,
    category_label: String,
    list: ReleaseList,
}

async fn adult_page(
    ctx: &Ctx,
    layout: Layout,
    input: &Input,
    signed_in: &nntmux_webkit::auth::Auth,
    sub: Option<String>,
) -> WebResult {
    const XXX_ROOT: i64 = 6000;
    let mut category = input.int("t", XXX_ROOT);
    let mut label = "All".to_owned();
    if let Some(sub) = &sub {
        let found: Option<i64> = sqlx::query_scalar(
            "SELECT CAST(id AS SIGNED) FROM categories WHERE title = ? AND root_categories_id = ?",
        )
        .bind(sub)
        .bind(XXX_ROOT)
        .fetch_optional(&ctx.db)
        .await?;
        if let Some(found) = found {
            category = found;
            label = sub.clone();
        }
    }
    let excluded = user::category_exclusions(&ctx.db, signed_in.user.id, &signed_in.access).await?;
    let categories = [category];
    let filter = BrowseFilter {
        categories: &categories,
        excluded: &excluded,
        group: None,
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
    let path = sub.as_deref().map_or_else(
        || "/XXX".to_owned(),
        |sub| format!("/XXX/{}", crate::releases::path_segment(sub)),
    );
    let page = AdultPage {
        layout: layout.titled(
            "Browse XXX",
            "browse,xxx,nzb,description,details",
            "Browse for XXX Movies",
        ),
        browse_title: "XXX".to_owned(),
        category_label: label,
        list: ReleaseList::new(&path, input, rows, total),
    };
    Ok(Html(page.render()?).into_response())
}

async fn adult(
    State(ctx): State<Ctx>,
    layout: Layout,
    input: Input,
    CurrentUser(signed_in): CurrentUser,
) -> WebResult {
    adult_page(&ctx, layout, &input, &signed_in, None).await
}

async fn adult_in(
    State(ctx): State<Ctx>,
    layout: Layout,
    input: Input,
    Path(id): Path<String>,
    CurrentUser(signed_in): CurrentUser,
) -> WebResult {
    adult_page(&ctx, layout, &input, &signed_in, Some(id)).await
}

#[derive(Template)]
#[template(path = "covers/movie.html")]
struct MoviePage {
    layout: Layout,
    card: Card,
    trailer: Option<String>,
}

const MOVIE_INFO: &str = "SELECT title, CAST(year AS CHAR), rating, genre, director, actors, plot, trailer FROM movieinfo WHERE imdbid = ? LIMIT 1";

async fn movie(
    State(ctx): State<Ctx>,
    layout: Layout,
    session: Session,
    Path(imdbid): Path<String>,
    CurrentUser(signed_in): CurrentUser,
) -> WebResult {
    let rows = text_rows(
        &ctx.db,
        MOVIE_INFO.to_owned(),
        std::slice::from_ref(&imdbid),
        8,
    )
    .await?;
    let Some(row) = rows.into_iter().next() else {
        session.flash("error", "Movie not found");
        return Ok(Redirect::to("/Movies").into_response());
    };
    let excluded = user::category_exclusions(&ctx.db, signed_in.user.id, &signed_in.access).await?;
    let mut clause = format!(
        " AND r.passwordstatus <= {}",
        password_limit(&ctx.db).await?
    );
    if !excluded.is_empty() {
        clause.push_str(&format!(
            " AND r.categories_id NOT IN ({})",
            id_list(&excluded)
        ));
    }
    let (releases, _) = releases_for(
        &ctx.db,
        &MOVIES,
        &imdbid,
        &clause,
        None,
        signed_in.user.timezone.as_deref(),
    )
    .await?;
    let title = if row[0].is_empty() {
        "Unknown Title".to_owned()
    } else {
        row[0].clone()
    };
    let values = [
        row[1].clone(),
        row[2].clone(),
        row[3].clone(),
        row[4].clone(),
        row[5].clone(),
        row[6].clone(),
    ];
    let card = card_from(&MOVIES, &imdbid, title.clone(), &values, releases, 0);
    let trailer = (layout.site.trailers_display != 0
        && (row[7].starts_with("https://") || row[7].starts_with("http://")))
    .then(|| row[7].clone());
    let layout = layout.titled(
        format!("{title} - Movie Details"),
        "movie,details,releases",
        format!("View all releases for {title}"),
    );
    Ok(Html(
        MoviePage {
            layout,
            card,
            trailer,
        }
        .render()?,
    )
    .into_response())
}

#[derive(Template)]
#[template(path = "covers/trailer.html")]
struct TrailerPage {
    layout: Layout,
    title: String,
    trailer: Option<String>,
}

async fn trailer(State(ctx): State<Ctx>, layout: Layout, input: Input) -> WebResult {
    let id = input.str("id");
    if id.is_empty() || !id.bytes().all(|byte| byte.is_ascii_digit()) {
        return Ok((
            StatusCode::BAD_REQUEST,
            Json(json!({"message": "Invalid movie ID."})),
        )
            .into_response());
    }
    let rows = text_rows(&ctx.db, MOVIE_INFO.to_owned(), std::slice::from_ref(&id), 8).await?;
    let Some(row) = rows.into_iter().next() else {
        return Ok((
            StatusCode::NOT_FOUND,
            Json(json!({"message": "There is no trailer for this movie."})),
        )
            .into_response());
    };
    let trailer =
        (row[7].starts_with("https://") || row[7].starts_with("http://")).then(|| row[7].clone());
    if input.has("modal") {
        #[derive(Template)]
        #[template(
            source = r#"{% if let Some(trailer) = trailer %}<iframe width="100%" height="480" src="{{ trailer }}" title="{{ title }}"></iframe>{% else %}<p>There is no trailer for this movie.</p>{% endif %}"#,
            ext = "html"
        )]
        struct TrailerModal {
            title: String,
            trailer: Option<String>,
        }
        return Ok(Html(
            TrailerModal {
                title: row[0].clone(),
                trailer,
            }
            .render()?,
        )
        .into_response());
    }
    let title = format!("Info for {}", row[0]);
    Ok(Html(
        TrailerPage {
            layout,
            title,
            trailer,
        }
        .render()?,
    )
    .into_response())
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

type TrendingRow = (String, String, Option<String>, Option<String>, i64, i64);

async fn trending_movies(State(ctx): State<Ctx>, layout: Layout) -> WebResult {
    let rows: Vec<TrendingRow> = sqlx::query_as(
        "SELECT m.imdbid, m.title, CAST(m.year AS CHAR), m.rating,
                CAST(COUNT(DISTINCT ud.id) AS SIGNED) AS total_downloads, CAST(COUNT(DISTINCT r.id) AS SIGNED)
         FROM movieinfo m
         JOIN releases r ON m.imdbid = r.imdbid
         JOIN user_downloads ud ON r.id = ud.releases_id
         WHERE m.title <> '' AND m.imdbid <> '' AND ud.timestamp >= DATE_SUB(NOW(), INTERVAL 48 HOUR)
         GROUP BY m.imdbid, m.title, m.year, m.rating
         ORDER BY total_downloads DESC LIMIT 15",
    )
    .fetch_all(&ctx.db)
    .await?;
    let items = rows
        .into_iter()
        .map(
            |(imdbid, title, year, rating, downloads, releases)| TrendingItem {
                cover: cover_url("movies", &format!("{imdbid}-cover"))
                    .unwrap_or_else(|| NO_COVER.to_owned()),
                href: format!("/movie/{imdbid}"),
                title,
                detail: [
                    year.unwrap_or_default(),
                    rating
                        .map(|rating| format!("Rating {rating}"))
                        .unwrap_or_default(),
                ]
                .into_iter()
                .filter(|part| !part.is_empty())
                .collect::<Vec<_>>()
                .join(" · "),
                downloads,
                releases,
            },
        )
        .collect();
    let layout = layout.titled(
        "Trending Movies - Last 48 Hours",
        "trending,movies,popular,downloads,recent",
        "Browse the most popular and downloaded movies in the last 48 hours",
    );
    Ok(Html(
        TrendingPage {
            layout,
            page_title: "Trending movies",
            items,
        }
        .render()?,
    )
    .into_response())
}

async fn update_layout(
    State(ctx): State<Ctx>,
    input: Input,
    CurrentUser(signed_in): CurrentUser,
) -> WebResult {
    let layout = input.int("layout", 0);
    if !matches!(layout, 1 | 2) {
        return Ok((StatusCode::UNPROCESSABLE_ENTITY, Json(json!({"message": "The selected layout is invalid.", "errors": {"layout": ["The selected layout is invalid."]}}))).into_response());
    }
    sqlx::query("UPDATE users SET movie_layout = ?, updated_at = NOW() WHERE id = ?")
        .bind(layout)
        .bind(signed_in.user.id)
        .execute(&ctx.db)
        .await?;
    Ok(Json(json!({"success": true, "layout": layout})).into_response())
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn filters_bind_every_value() {
        let input = Input::from_query("title=x%27%20OR%201=1&year=2024&rating=7&imdb=123");
        let (clause, binds) = filter_sql(&MOVIES, &input);
        assert!(!clause.contains("OR 1=1"));
        assert_eq!(binds, ["%x' OR 1=1%", "%7.%", "2024", "123"]);
    }

    #[test]
    fn a_bad_year_is_ignored() {
        let (clause, binds) = filter_sql(&MOVIES, &Input::from_query("year=1;DROP"));
        assert!(clause.is_empty() && binds.is_empty());
    }

    #[test]
    fn names_become_at_most_eight_links() {
        let names = (0..12)
            .map(|n| format!("A{n}"))
            .collect::<Vec<_>>()
            .join(", ");
        assert_eq!(names_to_links(&names, "/Movies", "actors").len(), 8);
        assert_eq!(
            names_to_links("Ann Lee", "/Movies", "actors")[0].1,
            "/Movies?actors=Ann+Lee"
        );
    }
}
