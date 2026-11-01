//! The API's browser routes: `/getnzb`, the file list and the help pages, behind the site's stack.

use askama::Template;
use axum::Router;
use axum::extract::{Path, State};
use axum::http::HeaderMap;
use axum::middleware::from_fn;
use axum::response::{Html, IntoResponse, Response};
use axum::routing::{MethodFilter, MethodRouter, get, on};
use nntmux_runtime::Ctx;
use nntmux_webkit::{CurrentUser, Layout, WebResult, guard};

use crate::filelist;
use crate::json::{J, Style};
use crate::nzb;
use crate::reply::Reply;

struct Category {
    id: u64,
    title: String,
}

#[derive(Template)]
#[template(path = "apihelp.html")]
struct ApiHelp {
    layout: Layout,
    page_title: &'static str,
    base: String,
    token: String,
}

#[derive(Template)]
#[template(path = "apiv2help.html")]
struct ApiV2Help {
    layout: Layout,
    page_title: &'static str,
    base: String,
    token: String,
}

#[derive(Template)]
#[template(path = "rsshelp.html")]
struct RssHelp {
    layout: Layout,
    page_title: &'static str,
    base: String,
    token: String,
    parent_categories: Vec<Category>,
    categories: Vec<Category>,
}

const HELP_KEYWORDS: &str = "view,nzb,api,details,help,json,rss,atom";

fn base_url(headers: &HeaderMap) -> String {
    crate::request::base_url(headers)
}

async fn api_help(layout: Layout, CurrentUser(auth): CurrentUser, headers: HeaderMap) -> WebResult {
    let page = ApiHelp {
        layout: layout.titled(
            "Api Help Topics",
            HELP_KEYWORDS,
            "View description of the site Nzb Api.",
        ),
        page_title: "Api Help",
        base: base_url(&headers),
        token: auth.user.api_token.clone(),
    };
    Ok(Html(page.render()?).into_response())
}

async fn api_v2_help(
    layout: Layout,
    CurrentUser(auth): CurrentUser,
    headers: HeaderMap,
) -> WebResult {
    let page = ApiV2Help {
        layout: layout.titled(
            "Api V2 Help Topics",
            HELP_KEYWORDS,
            "View description of the site Nzb version 2 Api.",
        ),
        page_title: "Api V2 Help",
        base: base_url(&headers),
        token: auth.user.api_token.clone(),
    };
    Ok(Html(page.render()?).into_response())
}

async fn rss_help(
    State(ctx): State<Ctx>,
    layout: Layout,
    CurrentUser(auth): CurrentUser,
    headers: HeaderMap,
) -> WebResult {
    let parents: Vec<(u64, String)> = sqlx::query_as(
        "SELECT r.id, r.title FROM root_categories r
         WHERE EXISTS (SELECT 1 FROM categories c WHERE c.root_categories_id = r.id)",
    )
    .fetch_all(&ctx.db)
    .await?;
    let categories: Vec<(u32, String)> =
        sqlx::query_as("SELECT id, title FROM categories WHERE status = 1 ORDER BY id")
            .fetch_all(&ctx.db)
            .await?;
    let page = RssHelp {
        layout: layout.titled(
            "Rss Nzb Info",
            "view,nzb,description,details,rss,atom",
            "View information about NNTmux RSS Feeds.",
        ),
        page_title: "Rss Info",
        base: base_url(&headers),
        token: auth.user.api_token.clone(),
        parent_categories: parents
            .into_iter()
            .map(|(id, title)| Category { id, title })
            .collect(),
        categories: categories
            .into_iter()
            .map(|(id, title)| Category {
                id: u64::from(id),
                title,
            })
            .collect(),
    };
    Ok(Html(page.render()?).into_response())
}

/// `FileListApiController`: what a release's NZB holds, for the details page.
async fn file_list(State(ctx): State<Ctx>, Path(guid): Path<String>) -> WebResult {
    let release: Option<String> =
        sqlx::query_scalar("SELECT searchname FROM releases WHERE guid = ? LIMIT 1")
            .bind(&guid)
            .fetch_optional(&ctx.db)
            .await?;
    let Some(searchname) = release else {
        return Ok(not_found("Release not found"));
    };
    let Some(xml) = nzb::read(&ctx, &guid).await? else {
        return Ok(not_found("NZB file not found"));
    };
    let files = filelist::parse(&xml);
    let total = i64::try_from(files.len()).unwrap_or(i64::MAX);
    let body = J::obj([
        (
            "release",
            J::obj([("guid", J::Str(guid)), ("searchname", J::Str(searchname))]),
        ),
        ("files", J::Arr(files)),
        ("total", J::Int(total)),
    ]);
    Ok(Reply::json(200, &body, Style::Plain).into_response())
}

fn not_found(message: &str) -> Response {
    Reply::json(404, &J::obj([("error", J::str(message))]), Style::Plain).into_response()
}

fn page_methods() -> MethodFilter {
    MethodFilter::GET.or(MethodFilter::POST)
}

pub fn router(download: MethodRouter<Ctx>) -> Router<Ctx> {
    let private = Router::new()
        .route("/apihelp", on(page_methods(), api_help))
        .route("/apiv2help", on(page_methods(), api_v2_help))
        .route("/rsshelp", on(page_methods(), rss_help))
        .route("/api/release/{guid}/filelist", get(file_list))
        .route_layer(from_fn(guard::verified))
        .route_layer(from_fn(guard::authenticated));
    let public = Router::new()
        .route("/getnzb", download.clone())
        .route("/getnzb/{guid}", download);
    nntmux_webkit::stack(private.merge(public))
}
