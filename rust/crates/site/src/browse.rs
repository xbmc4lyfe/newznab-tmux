//! Browsing releases by category and group, the group list, the basket and NFO pages.

use anyhow::Result;
use askama::Template;
use axum::Json;
use axum::Router;
use axum::extract::{Path, Request, State};
use axum::http::{HeaderMap, StatusCode, Uri};
use axum::middleware::{Next, from_fn};
use axum::response::{Html, IntoResponse, Redirect, Response};
use axum::routing::{get, post};
use chrono::{DateTime, NaiveDateTime, Utc};
use nntmux_runtime::Ctx;
use nntmux_webkit::auth::Auth;
use nntmux_webkit::{CurrentUser, Input, Layout, Pager, Session, WebError, WebResult, guard, user};
use serde_json::json;

use crate::auth::back;
use crate::profile::relative;
use crate::releases::{
    BrowseFilter, ReleaseList, browse_count, browse_rows, order_or_default, per_page,
};

pub fn router() -> Router<Ctx> {
    let cleared = Router::new()
        .route("/browse/{parent}", get(browse_parent).post(browse_parent))
        .route(
            "/browse/{parent}/{id}",
            get(browse_category).post(browse_category),
        )
        .route_layer(from_fn(clearance));
    Router::new()
        .route("/browse/All", get(browse_all).post(browse_all))
        .route("/browse/group", get(browse_group).post(browse_group))
        .route("/browsegroup", get(group_list).post(group_list))
        .route("/cart/index", get(cart).post(cart))
        .route("/cart/add", post(cart_add))
        .route("/cart/delete/{id}", post(cart_delete))
        .route("/nfo", get(nfo_missing).post(nfo_missing))
        .route("/nfo/{id}", get(nfo).post(nfo))
        .merge(cleared)
        .route_layer(from_fn(guard::two_factor))
        .route_layer(from_fn(guard::verified))
        .route_layer(from_fn(guard::authenticated))
}

/// Which root each path word needs, and the name the refusal page shows.
fn required_permission(name: &str) -> Option<(&'static str, &'static str)> {
    match name.to_ascii_lowercase().as_str() {
        "movies" | "movie" | "trending-movies" | "movietrailers" | "mymovies" => {
            Some(("view movies", "Movies"))
        }
        "console" => Some(("view console", "Console")),
        "books" => Some(("view books", "Books")),
        "audio" => Some(("view audio", "Audio")),
        "xxx" => Some(("view adult", "Adult")),
        "games" | "pc" => Some(("view pc", "PC")),
        "tv" | "series" | "trending-tv" | "myshows" => Some(("view tv", "TV")),
        _ => None,
    }
}

/// Laravel's `clearance` middleware: a category needs its direct `view` permission, and a
/// subcategory the account excluded stays closed.
pub async fn clearance(request: Request, next: Next) -> Response {
    let Some(auth) = request.extensions().get::<Auth>().cloned() else {
        return next.run(request).await;
    };
    let path = decode_path(request.uri());
    let mut parts = path.trim_matches('/').split('/');
    let first = parts.next().unwrap_or_default().to_owned();
    let browse = first.eq_ignore_ascii_case("browse");
    let name = if browse {
        parts.next().unwrap_or_default().to_owned()
    } else {
        first
    };
    let sub = if browse {
        parts.next().map(str::to_owned)
    } else {
        None
    };
    if let Some((permission, label)) = required_permission(&name)
        && !auth.access.has_direct_permission(permission)
    {
        return WebError::CategoryDisabled {
            category: label.to_owned(),
            subcategory: false,
        }
        .into_response();
    }
    if let Some(sub) = sub.filter(|sub| !sub.eq_ignore_ascii_case("all"))
        && let Some(ctx) = request.extensions().get::<Ctx>()
    {
        match excluded_subcategory(ctx, auth.user.id, &name, &sub).await {
            Ok(Some(title)) => {
                return WebError::CategoryDisabled {
                    category: format!("{name} - {title}"),
                    subcategory: true,
                }
                .into_response();
            }
            Ok(None) => {}
            Err(failure) => return WebError::from(failure).into_response(),
        }
    }
    next.run(request).await
}

fn decode_path(uri: &Uri) -> String {
    uri.path()
        .split('/')
        .map(nntmux_webkit::config::decode_component)
        .collect::<Vec<_>>()
        .join("/")
}

async fn excluded_subcategory(
    ctx: &Ctx,
    user_id: i64,
    parent: &str,
    sub: &str,
) -> Result<Option<String>> {
    Ok(sqlx::query_scalar(
        "SELECT c.title FROM categories c
         JOIN root_categories r ON r.id = c.root_categories_id
         JOIN user_excluded_categories u ON u.categories_id = c.id
         WHERE LOWER(r.title) = LOWER(?) AND LOWER(c.title) = LOWER(?) AND u.users_id = ? LIMIT 1",
    )
    .bind(parent)
    .bind(sub)
    .bind(user_id)
    .fetch_optional(&ctx.db)
    .await?)
}

#[derive(Template)]
#[template(path = "browse/index.html")]
struct BrowsePage {
    layout: Layout,
    browse_title: String,
    category_label: String,
    list: ReleaseList,
}

const BROWSE_KEYWORDS: &str = "browse,nzb,description,details";

/// Builds the list for a filter. The page's order comes only from the allowed list.
async fn build_list(
    ctx: &Ctx,
    signed_in: &Auth,
    path: &str,
    input: &Input,
    filter: &BrowseFilter<'_>,
) -> Result<ReleaseList> {
    let total = browse_count(&ctx.db, filter).await?;
    let order = order_or_default(&input.str("ob"));
    let limit = per_page();
    let offset = input.page().saturating_sub(1).saturating_mul(limit);
    // A page past the end has nothing to show, so don't make the database walk to it.
    let rows = if offset >= total {
        Vec::new()
    } else {
        browse_rows(
            &ctx.db,
            filter,
            order,
            limit,
            offset,
            signed_in.user.timezone.as_deref(),
        )
        .await?
    };
    Ok(ReleaseList::new(path, input, rows, total))
}

async fn browse_all(
    State(ctx): State<Ctx>,
    layout: Layout,
    input: Input,
    CurrentUser(signed_in): CurrentUser,
) -> WebResult {
    let excluded = user::category_exclusions(&ctx.db, signed_in.user.id, &signed_in.access).await?;
    let filter = BrowseFilter {
        categories: &[-1],
        excluded: &excluded,
        group: None,
        ..BrowseFilter::default()
    };
    let list = build_list(&ctx, &signed_in, "/browse/All", &input, &filter).await?;
    let page = BrowsePage {
        layout: layout.titled("Browse All Releases", BROWSE_KEYWORDS, "Browse for Nzbs"),
        browse_title: "Browse releases".to_owned(),
        category_label: "All".to_owned(),
        list,
    };
    Ok(Html(page.render()?).into_response())
}

async fn browse_parent(
    state: State<Ctx>,
    layout: Layout,
    input: Input,
    user: CurrentUser,
    Path(parent): Path<String>,
) -> WebResult {
    show_browse(state, layout, input, user, parent, "All".to_owned()).await
}

async fn browse_category(
    state: State<Ctx>,
    layout: Layout,
    input: Input,
    user: CurrentUser,
    Path((parent, id)): Path<(String, String)>,
) -> WebResult {
    show_browse(state, layout, input, user, parent, id).await
}

/// The cover-page link for a root: `(cover path, list parent)`.
fn cover_route(root: i64) -> Option<&'static str> {
    match root {
        1000 => Some("Console"),
        2000 => Some("Movies"),
        3000 => Some("Audio"),
        4000 => Some("Games"),
        6000 => Some("XXX"),
        7000 => Some("Books"),
        _ => None,
    }
}

async fn show_browse(
    State(ctx): State<Ctx>,
    layout: Layout,
    input: Input,
    CurrentUser(signed_in): CurrentUser,
    parent: String,
    id: String,
) -> WebResult {
    let parent_id: Option<i64> =
        sqlx::query_scalar("SELECT CAST(id AS SIGNED) FROM root_categories WHERE title = ?")
            .bind(&parent)
            .fetch_optional(&ctx.db)
            .await?;
    let category = if id == "All" {
        parent_id.unwrap_or(-1)
    } else {
        sqlx::query_scalar::<_, i64>("SELECT CAST(id AS SIGNED) FROM categories WHERE title = ? AND root_categories_id <=> ? LIMIT 1")
            .bind(&id)
            .bind(parent_id)
            .fetch_optional(&ctx.db)
            .await?
            .unwrap_or(-1)
    };
    let excluded = user::category_exclusions(&ctx.db, signed_in.user.id, &signed_in.access).await?;
    let path = if id == "All" {
        format!("/browse/{}", crate::releases::path_segment(&parent))
    } else {
        format!(
            "/browse/{}/{}",
            crate::releases::path_segment(&parent),
            crate::releases::path_segment(&id)
        )
    };
    let categories = [category];
    let filter = BrowseFilter {
        categories: &categories,
        excluded: &excluded,
        group: None,
        ..BrowseFilter::default()
    };
    let mut list = build_list(&ctx, &signed_in, &path, &input, &filter).await?;

    let root: Option<i64> = if category == -1 {
        None
    } else if id == "All" {
        parent_id
    } else {
        sqlx::query_scalar("SELECT CAST(root_categories_id AS SIGNED) FROM categories WHERE id = ?")
            .bind(category)
            .fetch_optional(&ctx.db)
            .await?
            .or(Some(category))
    };
    let tv = root == Some(5000);
    if tv {
        list.tv_rss_url = Some(format!(
            "/rss/myshows?dl=1&i={}&api_token={}",
            signed_in.user.id, signed_in.user.api_token
        ));
        list.cover_view_url = Some("/series".to_owned());
    } else if let Some(route) = root.and_then(cover_route) {
        list.cover_view_url = Some(if id == "All" {
            format!("/{route}")
        } else {
            format!("/{route}/{}", crate::releases::path_segment(&id))
        });
    }
    let category_label = if category == -1 || id.eq_ignore_ascii_case("all") {
        "All".to_owned()
    } else {
        id.clone()
    };
    list.search_placeholder = search_placeholder(&parent, &category_label);
    list.inline_search_url = if category > 0 {
        format!("/search?t={category}")
    } else {
        "/search".to_owned()
    };
    let title = if id == "All" && parent == "All" {
        format!("Browse {parent} releases")
    } else {
        format!("Browse {parent} / {id} releases")
    };
    let page = BrowsePage {
        layout: layout.titled(title, BROWSE_KEYWORDS, "Browse for Nzbs"),
        browse_title: if parent.is_empty() || parent == "All" {
            "Browse releases".to_owned()
        } else {
            uppercase_first(&parent)
        },
        category_label,
        list,
    };
    Ok(Html(page.render()?).into_response())
}

fn uppercase_first(text: &str) -> String {
    let mut chars = text.chars();
    chars
        .next()
        .map(|first| first.to_uppercase().chain(chars).collect())
        .unwrap_or_default()
}

fn search_placeholder(parent: &str, category: &str) -> String {
    let mut text = "Search".to_owned();
    if !parent.is_empty() && parent != "All" {
        text.push_str(" in ");
        text.push_str(parent);
        if !category.eq_ignore_ascii_case("all") {
            text.push(' ');
            text.push_str(category);
        }
    }
    text.push_str("...");
    text
}

async fn browse_group(
    State(ctx): State<Ctx>,
    layout: Layout,
    session: Session,
    input: Input,
    headers: HeaderMap,
    CurrentUser(signed_in): CurrentUser,
) -> WebResult {
    let Some(group) = input.get("g") else {
        session.flash("error", "Group parameter is required");
        return Ok(back(&headers, "/browsegroup"));
    };
    let excluded = user::category_exclusions(&ctx.db, signed_in.user.id, &signed_in.access).await?;
    let filter = BrowseFilter {
        categories: &[-1],
        excluded: &excluded,
        group: Some(&group),
        ..BrowseFilter::default()
    };
    let mut list = build_list(&ctx, &signed_in, "/browse/group", &input, &filter).await?;
    list.search_placeholder = search_placeholder(&group, "all");
    let page = BrowsePage {
        layout: layout.titled("Browse Groups", BROWSE_KEYWORDS, "Browse Groups"),
        browse_title: group,
        category_label: "All".to_owned(),
        list,
    };
    Ok(Html(page.render()?).into_response())
}

struct GroupRow {
    name: String,
    description: String,
    last_updated: String,
    updated_ago: String,
}

impl GroupRow {
    fn short_name(&self) -> String {
        self.name.replace("alt.binaries", "a.b")
    }

    fn browse_url(&self) -> String {
        format!(
            "/browse/group?g={}",
            nntmux_webkit::config::encode_component(&self.name)
        )
    }
}

#[derive(Template)]
#[template(path = "browse/groups.html")]
struct GroupsPage {
    layout: Layout,
    results: Vec<GroupRow>,
    pager: Pager,
    search: String,
}

async fn group_list(State(ctx): State<Ctx>, layout: Layout, input: Input) -> WebResult {
    let search = input.str("search");
    let pattern = format!("%{search}%");
    let total: i64 = sqlx::query_scalar(
        "SELECT COUNT(*) FROM usenet_groups WHERE active = 1 AND (? = '' OR name LIKE ?)",
    )
    .bind(&search)
    .bind(&pattern)
    .fetch_one(&ctx.db)
    .await?;
    let pager = Pager::new(
        "/browsegroup",
        &input.query_pairs(),
        total.max(0) as u64,
        per_page(),
        input.page(),
    );
    let rows: Vec<(String, Option<String>, Option<NaiveDateTime>)> = sqlx::query_as(
        "SELECT name, description, last_updated FROM usenet_groups
         WHERE active = 1 AND (? = '' OR name LIKE ?) ORDER BY name, id LIMIT ? OFFSET ?",
    )
    .bind(&search)
    .bind(&pattern)
    .bind(pager.per_page)
    .bind(pager.offset())
    .fetch_all(&ctx.db)
    .await?;
    let now = Utc::now();
    let results = rows
        .into_iter()
        .map(|(name, description, updated)| GroupRow {
            name,
            description: description.unwrap_or_default(),
            last_updated: updated.map(|at| at.to_string()).unwrap_or_default(),
            updated_ago: updated.map_or_else(|| "N/A".to_owned(), |at| relative(at.and_utc(), now)),
        })
        .collect();
    let (title, description) = if search.is_empty() {
        ("Browse Groups".to_owned(), "Browse groups".to_owned())
    } else {
        (
            format!("Browse Groups - Search: {search}"),
            format!("Browse groups search results for {search}"),
        )
    };
    let page = GroupsPage {
        layout: layout.titled(title, "browse,groups,description,details", description),
        results,
        pager,
        search,
    };
    Ok(Html(page.render()?).into_response())
}

struct CartRow {
    guid: String,
    searchname: String,
    added: String,
    added_ago: String,
}

impl CartRow {
    fn short_guid(&self) -> String {
        self.guid.chars().take(7).collect()
    }

    /// `Str::limit(..., 50)`
    fn short_name(&self) -> String {
        if self.searchname.chars().count() <= 50 {
            return self.searchname.clone();
        }
        let cut: String = self.searchname.chars().take(50).collect();
        format!("{}...", cut.trim_end())
    }
}

#[derive(Template)]
#[template(path = "cart/index.html")]
struct CartPage {
    layout: Layout,
    results: Vec<CartRow>,
    rss_url: String,
}

async fn cart(
    State(ctx): State<Ctx>,
    layout: Layout,
    CurrentUser(signed_in): CurrentUser,
) -> WebResult {
    let rows: Vec<(String, String, Option<DateTime<Utc>>)> = sqlx::query_as(
        "SELECT r.guid, r.searchname, u.created_at FROM users_releases u
         JOIN releases r ON r.id = u.releases_id WHERE u.users_id = ? ORDER BY u.created_at DESC",
    )
    .bind(signed_in.user.id)
    .fetch_all(&ctx.db)
    .await?;
    let now = Utc::now();
    let results = rows
        .into_iter()
        .map(|(guid, searchname, added)| CartRow {
            guid,
            searchname,
            added: added.map(|at| at.to_string()).unwrap_or_default(),
            added_ago: added.map(|at| relative(at, now)).unwrap_or_default(),
        })
        .collect();
    let page = CartPage {
        layout: layout.titled(
            "My Download Basket",
            "search,add,to,cart,download,basket,nzb,description,details",
            "Manage Your Download Basket",
        ),
        results,
        rss_url: format!(
            "/rss/cart?dl=1&i={}&api_token={}&del=1",
            signed_in.user.id, signed_in.user.api_token
        ),
    };
    Ok(Html(page.render()?).into_response())
}

/// One basket click, not an unbounded list of queries.
const MAX_CART_ADD: usize = 500;

fn split_guids(text: &str) -> Vec<String> {
    let mut seen = Vec::new();
    for guid in text
        .split(',')
        .map(str::trim)
        .filter(|guid| !guid.is_empty())
    {
        if !seen.iter().any(|known| known == guid) {
            seen.push(guid.to_owned());
        }
    }
    seen
}

async fn cart_add(
    State(ctx): State<Ctx>,
    input: Input,
    headers: HeaderMap,
    CurrentUser(signed_in): CurrentUser,
) -> WebResult {
    let wants_json = headers.contains_key("x-requested-with") || guard::expects_json(&headers);
    let mut guids = split_guids(&input.str("id"));
    guids.truncate(MAX_CART_ADD);
    let mut release_ids: Vec<i64> = Vec::new();
    for guid in &guids {
        if let Some(id) =
            sqlx::query_scalar::<_, i64>("SELECT CAST(id AS SIGNED) FROM releases WHERE guid = ?")
                .bind(guid)
                .fetch_optional(&ctx.db)
                .await?
        {
            release_ids.push(id);
        }
    }
    if release_ids.is_empty() {
        if wants_json {
            return Ok((
                StatusCode::NOT_FOUND,
                Json(json!({"success": false, "message": "No releases found"})),
            )
                .into_response());
        }
        return Ok(Redirect::to("/cart/index").into_response());
    }
    let mut added = 0;
    for id in release_ids {
        added += sqlx::query(
            "INSERT IGNORE INTO users_releases (users_id, releases_id, created_at, updated_at)
             SELECT ?, ?, NOW(), NOW() FROM DUAL
             WHERE NOT EXISTS (SELECT 1 FROM users_releases WHERE users_id = ? AND releases_id = ?)",
        )
        .bind(signed_in.user.id)
        .bind(id)
        .bind(signed_in.user.id)
        .bind(id)
        .execute(&ctx.db)
        .await?
        .rows_affected();
    }
    if !wants_json {
        return Ok(Redirect::to("/cart/index").into_response());
    }
    let count: i64 = sqlx::query_scalar("SELECT COUNT(*) FROM users_releases WHERE users_id = ?")
        .bind(signed_in.user.id)
        .fetch_one(&ctx.db)
        .await?;
    let message = if added > 0 {
        format!("{added} item(s) added to cart")
    } else {
        "Items already in cart".to_owned()
    };
    Ok(Json(json!({"success": true, "message": message, "cartCount": count})).into_response())
}

async fn cart_delete(
    State(ctx): State<Ctx>,
    Path(id): Path<String>,
    CurrentUser(signed_in): CurrentUser,
) -> WebResult {
    for guid in split_guids(&id) {
        sqlx::query(
            "DELETE u FROM users_releases u JOIN releases r ON r.id = u.releases_id WHERE u.users_id = ? AND r.guid = ?",
        )
        .bind(signed_in.user.id)
        .bind(&guid)
        .execute(&ctx.db)
        .await?;
    }
    Ok(Redirect::to("/cart/index").into_response())
}

#[derive(Template)]
#[template(path = "browse/nfo.html")]
struct NfoPage {
    layout: Layout,
    guid: String,
    text: String,
}

#[derive(Template)]
#[template(source = "<pre class=\"nfo-content\">{{ text }}</pre>", ext = "html")]
struct NfoModal {
    text: String,
}

/// CP437 art to UTF-8, what PHP's `cp437toUTF` did. The first 128 are ASCII.
pub fn cp437_to_utf8(bytes: &[u8]) -> String {
    const HIGH: &str = "ÇüéâäàåçêëèïîìÄÅÉæÆôöòûùÿÖÜ¢£¥₧ƒáíóúñÑªº¿⌐¬½¼¡«»░▒▓│┤╡╢╖╕╣║╗╝╜╛┐└┴┬├─┼╞╟╚╔╩╦╠═╬╧╨╤╥╙╘╒╓╫╪┘┌█▄▌▐▀αßΓπΣσµτΦΘΩδ∞φε∩≡±≥≤⌠⌡÷≈°∙·√ⁿ²■\u{a0}";
    let high: Vec<char> = HIGH.chars().collect();
    bytes
        .iter()
        .map(|&byte| {
            if byte < 128 {
                byte as char
            } else {
                high[usize::from(byte) - 128]
            }
        })
        .collect()
}

async fn nfo_missing() -> WebResult {
    Err(WebError::NotFound)
}

async fn nfo(
    State(ctx): State<Ctx>,
    layout: Layout,
    input: Input,
    Path(guid): Path<String>,
) -> WebResult {
    let release: Option<(i64, String)> =
        sqlx::query_as("SELECT CAST(id AS SIGNED), searchname FROM releases WHERE guid = ?")
            .bind(&guid)
            .fetch_optional(&ctx.db)
            .await?;
    let (id, name) = release.ok_or(WebError::NotFound)?;
    let data: Option<Vec<u8>> = sqlx::query_scalar(
        "SELECT UNCOMPRESS(nfo) FROM release_nfos WHERE releases_id = ? AND nfo IS NOT NULL",
    )
    .bind(id)
    .fetch_optional(&ctx.db)
    .await?
    .flatten();
    let data = data.ok_or(WebError::NotFound)?;
    let text = cp437_to_utf8(&data);
    if input.has("modal") {
        return Ok(Html(NfoModal { text }.render()?).into_response());
    }
    let page = NfoPage {
        layout: layout.titled(
            format!("View NFO - {name}"),
            "view,nzb,nfo,description,details",
            format!("View NFO File for {name}"),
        ),
        guid,
        text,
    };
    Ok(Html(page.render()?).into_response())
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn cp437_maps_block_art() {
        assert_eq!(cp437_to_utf8(&[0x41, 0xDB, 0xB0, 0x80, 0xFF]), "A█░Ç\u{a0}");
        assert_eq!(cp437_to_utf8(b"plain\n"), "plain\n");
    }

    #[test]
    fn permissions_cover_the_paths_laravel_listed() {
        assert_eq!(required_permission("MOVIES").unwrap().0, "view movies");
        assert_eq!(required_permission("trending-tv").unwrap().1, "TV");
        assert_eq!(required_permission("games").unwrap().0, "view pc");
        assert_eq!(required_permission("other"), None);
    }

    #[test]
    fn guid_lists_split_and_deduplicate() {
        assert_eq!(split_guids("a, b,,a"), ["a", "b"]);
    }
}
