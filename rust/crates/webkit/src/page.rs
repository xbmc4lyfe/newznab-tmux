//! Data for `layout.html`. A page's template has a `layout: Layout` field and takes it as an
//! extractor, then calls [`Layout::titled`].

use std::collections::HashSet;

use axum::extract::FromRequestParts;
use axum::http::StatusCode;
use axum::http::request::Parts;
use chrono::{Datelike, Duration, Utc};
use nntmux_runtime::Ctx;
use serde_json::{Value, json};
use sqlx::{FromRow, MySqlPool};

use crate::assets::{Assets, assets};
use crate::auth::{self, Auth};
use crate::client_ip;
use crate::config::{self, encode_component};
use crate::error::WebError;
use crate::headers::CspNonce;
use crate::input::Input;
use crate::session::Session;
use crate::user;

pub const TV_ROOT: i64 = 5000;
pub const MOVIE_ROOT: i64 = 2000;
pub const GAME_ROOT: i64 = 1000;
pub const PC_ROOT: i64 = 4000;
pub const MUSIC_ROOT: i64 = 3000;
pub const BOOKS_ROOT: i64 = 7000;
pub const XXX_ROOT: i64 = 6000;
pub const MOVIE_FOREIGN: i64 = 2010;
pub const TV_FOREIGN: i64 = 5020;
pub const PC_GAMES: i64 = 4050;

/// The columns of `site_configurations`, with Laravel's defaults when the row is absent.
#[derive(Clone, Debug, FromRow)]
pub struct SiteConfig {
    pub title: String,
    pub home_link: String,
    pub site_logo: Option<String>,
    pub strapline: String,
    pub meta_title: String,
    pub meta_description: String,
    pub meta_keywords: String,
    pub footer: String,
    pub dereferrer_link: String,
    pub terms: String,
    pub trailers_display: i8,
    pub trailers_size_x: i32,
    pub trailers_size_y: i32,
}

impl Default for SiteConfig {
    fn default() -> Self {
        Self {
            title: "NNTmux".to_owned(),
            home_link: "/".to_owned(),
            site_logo: None,
            strapline: "A great usenet indexer".to_owned(),
            meta_title: "An indexer".to_owned(),
            meta_description: "A usenet indexing website".to_owned(),
            meta_keywords: "usenet,nzbs,cms,community".to_owned(),
            footer: "Usenet binary indexer.".to_owned(),
            dereferrer_link: String::new(),
            terms: String::new(),
            trailers_display: 1,
            trailers_size_x: 480,
            trailers_size_y: 345,
        }
    }
}

impl SiteConfig {
    pub async fn load(db: &MySqlPool) -> anyhow::Result<Self> {
        let found = sqlx::query_as(
            "SELECT title, home_link, site_logo, strapline, meta_title, meta_description, meta_keywords, footer,
                    dereferrer_link, terms, trailers_display, CAST(trailers_size_x AS SIGNED) AS trailers_size_x,
                    CAST(trailers_size_y AS SIGNED) AS trailers_size_y
             FROM site_configurations ORDER BY id LIMIT 1",
        )
        .fetch_optional(db)
        .await?;
        Ok(found.unwrap_or_default())
    }

    /// `/storage/<path>` for a logo the admin uploaded, and nothing for anything else.
    pub fn logo_url(&self) -> Option<String> {
        self.site_logo
            .as_deref()
            .filter(|path| !path.contains("..") && path.starts_with("site-logos/"))
            .map(|path| format!("/storage/{path}"))
    }
}

#[derive(Clone, Debug)]
pub struct NavLink {
    pub label: String,
    pub href: String,
}

#[derive(Clone, Debug)]
pub struct NavSub {
    pub id: i64,
    pub title: String,
    pub href: String,
    /// Language searches shown in a flyout. Empty for ordinary subcategories.
    pub foreign: Vec<NavLink>,
}

#[derive(Clone, Debug)]
pub struct NavCategory {
    pub id: i64,
    pub title: String,
    pub icon: &'static str,
    /// Links above the divider, such as "All TV".
    pub links: Vec<NavLink>,
    pub subs: Vec<NavSub>,
}

#[derive(Clone, Debug)]
pub struct UsefulLink {
    pub title: String,
    pub url: Option<String>,
    pub external: bool,
    /// Admin-entered HTML, rendered as written.
    pub body: String,
}

#[derive(Clone, Debug)]
pub struct Swatch {
    pub value: &'static str,
    pub label: &'static str,
    pub class: &'static str,
}

const SWATCHES: [(&str, &str); 11] = [
    ("blue", "Blue"),
    ("indigo", "Indigo"),
    ("cyan", "Cyan"),
    ("teal", "Teal"),
    ("emerald", "Emerald"),
    ("violet", "Violet"),
    ("pink", "Pink"),
    ("rose", "Rose"),
    ("red", "Red"),
    ("orange", "Orange"),
    ("amber", "Amber"),
];

const FOREIGN_SEARCHES: [(&str, &str); 10] = [
    ("German", "German|Deutsch|DE|GER"),
    ("French", "French|Francais|FR|FRENCH|VOSTFR|TRUEFRENCH"),
    ("Spanish", "Spanish|Espanol|ES|ESP|SPANISH|Latino"),
    ("Italian", "Italian|Italiano|IT|ITA"),
    ("Dutch", "Dutch|NL|Flemish|Nederlands|DUTCH"),
    ("Japanese", "Japanese|JP|JPN|JAP"),
    ("Korean", "Korean|KR|KOR|K-Drama"),
    ("Chinese", "Chinese|Mandarin|Cantonese|CH|CHN|C-Drama"),
    ("Russian", "Russian|RU|RUS"),
    ("Portuguese", "Portuguese|PT|POR|Portugues|Brazilian"),
];

fn foreign_links(category: i64) -> Vec<NavLink> {
    FOREIGN_SEARCHES
        .iter()
        .map(|(label, search)| NavLink {
            label: (*label).to_owned(),
            href: format!("/search?search={}&t={category}", encode_component(search)),
        })
        .collect()
}

fn icon(root: i64) -> &'static str {
    match root {
        TV_ROOT => "fa-television",
        MOVIE_ROOT => "fa-film",
        GAME_ROOT => "fa-gamepad",
        PC_ROOT => "fa-desktop",
        MUSIC_ROOT => "fa-music",
        BOOKS_ROOT => "fa-book",
        XXX_ROOT => "fa-ban",
        _ => "fa-folder",
    }
}

/// Which view switches change a category's links. Those with the cover view on go to the cover
/// page rather than the plain list.
#[derive(Clone, Copy, Default)]
struct Views {
    movie: bool,
    console: bool,
    game: bool,
}

fn category_nav(root: i64, title: &str, subs: Vec<(i64, String)>, views: Views) -> NavCategory {
    let link = |label: String, href: String| NavLink { label, href };
    let covers = (root == MOVIE_ROOT && views.movie) || (root == GAME_ROOT && views.console);
    let top = if covers { format!("/{title}") } else { format!("/browse/{title}") };
    let links = match root {
        TV_ROOT => vec![
            link("All TV".to_owned(), format!("/browse/{title}")),
            link("TV Series".to_owned(), "/series".to_owned()),
        ],
        MOVIE_ROOT => vec![
            link(title.to_owned(), top),
            link("My Movies".to_owned(), "/mymovies".to_owned()),
        ],
        _ => vec![link(title.to_owned(), top)],
    };
    let subs = subs
        .into_iter()
        .map(|(id, sub)| {
            let (href, foreign) = match (root, id) {
                (TV_ROOT, TV_FOREIGN) => (format!("/browse/TV/{sub}"), foreign_links(id)),
                (TV_ROOT, _) => (format!("/browse/TV/{sub}"), Vec::new()),
                (MOVIE_ROOT, MOVIE_FOREIGN) if views.movie => (format!("/{title}/{sub}"), foreign_links(id)),
                (MOVIE_ROOT, MOVIE_FOREIGN) => (format!("/browse/{title}/{sub}"), foreign_links(id)),
                (PC_ROOT, PC_GAMES) if views.game => (format!("/{sub}"), Vec::new()),
                _ if covers => (format!("/{title}/{sub}"), Vec::new()),
                _ => (format!("/browse/{title}/{sub}"), Vec::new()),
            };
            NavSub { id, title: sub, href, foreign }
        })
        .collect();
    NavCategory { id: root, title: title.to_owned(), icon: icon(root), links, subs }
}

/// `Category::getForMenu`: roots that still have a visible child, with those children.
async fn load_nav(db: &MySqlPool, excluded: &[i64], views: Views) -> anyhow::Result<Vec<NavCategory>> {
    let roots: Vec<(i64, String)> = sqlx::query_as("SELECT CAST(id AS SIGNED), title FROM root_categories ORDER BY id")
        .fetch_all(db)
        .await?;
    let children: Vec<(i64, i64, String)> = sqlx::query_as(
        "SELECT CAST(id AS SIGNED), CAST(root_categories_id AS SIGNED), title FROM categories
         WHERE root_categories_id IS NOT NULL ORDER BY id",
    )
    .fetch_all(db)
    .await?;
    let hidden: HashSet<i64> = excluded.iter().copied().collect();
    Ok(roots
        .into_iter()
        .filter_map(|(root, title)| {
            let subs: Vec<(i64, String)> = children
                .iter()
                .filter(|(id, parent, _)| *parent == root && !hidden.contains(id))
                .map(|(id, _, name)| (*id, name.clone()))
                .collect();
            (!subs.is_empty()).then(|| category_nav(root, &title, subs, views))
        })
        .collect())
}

/// `Content::resolved_url`: absolute links kept, bare domains made https, the rest made local.
pub fn resolve_content_url(url: &str) -> (Option<String>, bool) {
    if url.is_empty() {
        return (None, false);
    }
    if url.starts_with("http://") || url.starts_with("https://") {
        return (Some(url.to_owned()), true);
    }
    if !url.starts_with('/') && looks_like_domain(url) {
        return (Some(format!("https://{url}")), true);
    }
    (Some(format!("/{}", url.trim_start_matches('/'))), false)
}

/// `^[a-zA-Z0-9][a-zA-Z0-9-]*\.[a-zA-Z]{2,}`
fn looks_like_domain(url: &str) -> bool {
    let Some((label, rest)) = url.split_once('.') else {
        return false;
    };
    let mut label_chars = label.chars();
    label_chars.next().is_some_and(|first| first.is_ascii_alphanumeric())
        && label_chars.all(|c| c.is_ascii_alphanumeric() || c == '-')
        && rest.chars().take_while(char::is_ascii_alphabetic).count() >= 2
}

/// PHP's `html_entity_decode` for what useful-link bodies hold, after trimming wrapping quotes.
fn useful_link_body(body: &str) -> String {
    body.trim_matches(|c| c == '\'' || c == '"')
        .replace("&lt;", "<")
        .replace("&gt;", ">")
        .replace("&quot;", "\"")
        .replace("&#039;", "'")
        .replace("&#39;", "'")
        .replace("&amp;", "&")
}

async fn load_useful_links(db: &MySqlPool) -> anyhow::Result<Vec<UsefulLink>> {
    let rows: Vec<(String, Option<String>, Option<String>)> = sqlx::query_as(
        "SELECT title, url, body FROM content WHERE status = 1 AND contenttype = 1
         ORDER BY contenttype, COALESCE(ordinal, 1000000) ASC, id ASC",
    )
    .fetch_all(db)
    .await?;
    Ok(rows
        .into_iter()
        .map(|(title, url, body)| {
            let (url, external) = resolve_content_url(url.as_deref().unwrap_or_default());
            let body = useful_link_body(body.as_deref().unwrap_or_default());
            UsefulLink { title, url, external, body }
        })
        .collect())
}

/// Everything the shared layout shows.
#[derive(Clone)]
pub struct Layout {
    pub meta_title: String,
    pub meta_keywords: String,
    pub meta_description: String,
    pub app_name: String,
    pub site: SiteConfig,
    pub logo_url: Option<String>,
    pub csrf: String,
    pub nonce: String,
    pub assets: Assets,
    pub signed_in: bool,
    pub is_admin: bool,
    pub username: String,
    pub initial: String,
    pub theme: String,
    pub scheme: String,
    pub year: i32,
    pub nav: Vec<NavCategory>,
    pub useful_links: Vec<UsefulLink>,
    pub header_cat: String,
    pub header_search: String,
    pub swatches: Vec<Swatch>,
    pub flash_success: Option<String>,
    pub flash_errors: Vec<String>,
    pub flash_json: String,
    pub show_console: bool,
    pub show_movies: bool,
    pub show_audio: bool,
    pub show_games: bool,
    pub show_tv: bool,
    pub show_adult: bool,
    pub show_books: bool,
    pub upgrade_label: Option<&'static str>,
}

impl Layout {
    /// Sets the page title, the description and the keywords.
    pub fn titled(mut self, title: impl Into<String>, keywords: impl Into<String>, description: impl Into<String>) -> Self {
        self.meta_title = title.into();
        self.meta_keywords = keywords.into();
        self.meta_description = description.into();
        self
    }

    /// `Title - Site meta title`, or whichever part is present.
    pub fn full_title(&self) -> String {
        if self.meta_title.is_empty() {
            return format!("{}{}", self.app_name, self.site.meta_title);
        }
        if self.site.meta_title.is_empty() {
            return self.meta_title.clone();
        }
        format!("{} - {}", self.meta_title, self.site.meta_title)
    }

    /// The boolean text that `data-authenticated` carries.
    pub fn authenticated_attr(&self) -> &'static str {
        if self.signed_in { "true" } else { "false" }
    }

    pub fn theme_title(&self) -> String {
        let mut chars = self.theme.chars();
        chars
            .next()
            .map(|first| first.to_uppercase().chain(chars).collect())
            .unwrap_or_default()
    }

    pub fn theme_icon(&self) -> &'static str {
        match self.theme.as_str() {
            "dark" => "fa-moon",
            "system" => "fa-desktop",
            _ => "fa-sun",
        }
    }
}

fn flash_messages(session: &Session) -> (Option<String>, Vec<String>, String) {
    let error = session.flashed("error");
    let errors = match &error {
        Some(Value::String(message)) => vec![message.clone()],
        Some(Value::Array(items)) => items.iter().filter_map(Value::as_str).map(str::to_owned).collect(),
        _ => Vec::new(),
    };
    let success = session.flashed_str("success");
    let data = json!({
        "success": session.flashed("success"),
        "error": error,
        "warning": session.flashed("warning"),
        "info": session.flashed("info"),
    });
    (success, errors, data.to_string())
}

impl FromRequestParts<Ctx> for Layout {
    type Rejection = WebError;

    async fn from_request_parts(parts: &mut Parts, ctx: &Ctx) -> Result<Self, Self::Rejection> {
        let session = parts
            .extensions
            .get::<Session>()
            .cloned()
            .ok_or_else(|| WebError::Internal(anyhow::anyhow!("the web stack isn't installed")))?;
        let nonce = parts
            .extensions
            .get::<CspNonce>()
            .map(|nonce| nonce.0.clone())
            .ok_or(StatusCode::INTERNAL_SERVER_ERROR)
            .map_err(|_| WebError::Internal(anyhow::anyhow!("no CSP nonce")))?;
        let auth = parts.extensions.get::<Auth>().cloned();
        let input = Input::from_request_parts(parts, ctx).await.unwrap_or_default();

        let site = SiteConfig::load(&ctx.db).await?;
        let useful_links = load_useful_links(&ctx.db).await?;
        let (flash_success, flash_errors, flash_json) = flash_messages(&session);

        let mut layout = Self {
            meta_title: String::new(),
            meta_keywords: String::new(),
            meta_description: String::new(),
            app_name: config::app_name(),
            logo_url: site.logo_url(),
            site,
            csrf: session.token(),
            nonce,
            assets: assets().clone(),
            signed_in: false,
            is_admin: false,
            username: String::new(),
            initial: String::new(),
            theme: "light".to_owned(),
            scheme: "blue".to_owned(),
            year: Utc::now().year(),
            nav: Vec::new(),
            useful_links,
            header_cat: input.str("t"),
            header_search: input.str("search"),
            swatches: SWATCHES
                .iter()
                .map(|(value, label)| Swatch {
                    value,
                    label,
                    class: swatch_class(value),
                })
                .collect(),
            flash_success,
            flash_errors,
            flash_json,
            show_console: false,
            show_movies: false,
            show_audio: false,
            show_games: false,
            show_tv: false,
            show_adult: false,
            show_books: false,
            upgrade_label: None,
        };

        if let Some(auth) = auth {
            let user = &auth.user;
            // Keeps `lastlogin` fresh for long-lived sessions, as the view composer did.
            let stale = user
                .lastlogin
                .is_none_or(|at| at < (Utc::now() - Duration::hours(3)).naive_utc());
            if stale {
                let ip = client_ip::from_parts(parts).to_string();
                auth::log_if_failed(auth::record_login(ctx, user.id, &ip).await, "updating lastlogin");
            }
            let excluded = user::category_exclusions(&ctx.db, user.id, &auth.access).await?;
            let views = Views {
                movie: user.movieview == 1,
                console: user.consoleview == 1,
                game: user.gameview == 1,
            };
            layout.nav = load_nav(&ctx.db, &excluded, views).await?;
            layout.signed_in = true;
            layout.is_admin = auth.access.has_role("Admin");
            layout.username = user.username.clone();
            layout.initial = user.username.chars().take(1).flat_map(char::to_uppercase).collect();
            layout.theme = user.theme_preference.clone();
            layout.scheme = user.color_scheme.clone();
            let access = &auth.access;
            layout.show_console = user.consoleview != 0 && access.can("view console");
            layout.show_movies = user.movieview != 0 && access.can("view movies");
            layout.show_audio = user.musicview != 0 && access.can("view audio");
            layout.show_games = user.gameview != 0 && access.can("view pc");
            layout.show_tv = access.can("view tv");
            layout.show_adult = user.xxxview != 0 && access.can("view adult");
            layout.show_books = user.bookview != 0 && access.can("view books");
            layout.upgrade_label = match access.roles.first().map(String::as_str) {
                Some("Admin") => None,
                Some("User") | None => Some("Upgrade Your Account"),
                Some(_) => Some("Extend Your Account"),
            };
        }
        Ok(layout)
    }
}

fn swatch_class(value: &str) -> &'static str {
    // Tailwind only ships classes it can see written out.
    match value {
        "indigo" => "bg-indigo-600",
        "cyan" => "bg-cyan-600",
        "teal" => "bg-teal-600",
        "emerald" => "bg-emerald-600",
        "violet" => "bg-violet-600",
        "pink" => "bg-pink-600",
        "rose" => "bg-rose-600",
        "red" => "bg-red-600",
        "orange" => "bg-orange-600",
        "amber" => "bg-amber-600",
        _ => "bg-blue-600",
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn content_urls_resolve_like_laravel() {
        assert_eq!(resolve_content_url(""), (None, false));
        assert_eq!(resolve_content_url("https://a.b/c"), (Some("https://a.b/c".to_owned()), true));
        assert_eq!(resolve_content_url("chatgpt.ai"), (Some("https://chatgpt.ai".to_owned()), true));
        assert_eq!(resolve_content_url("/local"), (Some("/local".to_owned()), false));
        assert_eq!(resolve_content_url("local/page"), (Some("/local/page".to_owned()), false));
    }

    #[test]
    fn nav_links_follow_the_view_switches() {
        let subs = vec![(2010, "Foreign".to_owned()), (2040, "HD".to_owned())];
        let plain = category_nav(MOVIE_ROOT, "Movies", subs.clone(), Views::default());
        assert_eq!(plain.links[0].href, "/browse/Movies");
        assert_eq!(plain.subs[1].href, "/browse/Movies/HD");
        assert_eq!(plain.subs[0].foreign.len(), 10);
        let covers = category_nav(MOVIE_ROOT, "Movies", subs, Views { movie: true, ..Views::default() });
        assert_eq!(covers.links[0].href, "/Movies");
        assert_eq!(covers.subs[1].href, "/Movies/HD");
        let pc = category_nav(PC_ROOT, "PC", vec![(4050, "Games".to_owned())], Views { game: true, ..Views::default() });
        assert_eq!(pc.subs[0].href, "/Games");
    }

    #[test]
    fn useful_link_bodies_unwrap() {
        assert_eq!(useful_link_body("'&lt;b&gt;x&lt;/b&gt;'"), "<b>x</b>");
    }
}
