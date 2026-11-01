//! Public and informational pages: home and content, terms, privacy, contact, service status,
//! cover images and release reports.

use std::collections::BTreeMap;
use std::path::PathBuf;

use anyhow::Result;
use askama::Template;
use axum::extract::{Path, State};
use axum::http::{HeaderValue, StatusCode, header};
use axum::middleware::from_fn;
use axum::response::{Html, IntoResponse, Redirect, Response};
use axum::routing::{get, post};
use axum::{Json, Router};
use chrono::{DateTime, Utc};
use nntmux_core::var_or;
use nntmux_runtime::Ctx;
use nntmux_webkit::assets::public_dir;
use nntmux_webkit::page::resolve_entities;
use nntmux_webkit::{
    ClientIp, CurrentUser, Input, Layout, MaybeUser, Session, WebError, WebResult, config, guard,
};
use serde_json::json;
use sqlx::FromRow;

use crate::auth::captcha_error;
use crate::guest::Captcha;
use crate::{mailer, validate};

const ROLE_EVERYONE: i32 = 0;
const ROLE_LOGGED_IN: i32 = 1;
const ROLE_ADMIN: i32 = 2;
const ROLE_MODERATOR: i32 = 4;
const TYPE_INDEX: i32 = 3;

pub fn router() -> Router<Ctx> {
    let home = Router::new()
        .route("/", get(content).post(content))
        .route_layer(from_fn(guard::two_factor))
        .route_layer(from_fn(guard::authenticated));
    let private = Router::new()
        .route("/content", get(content).post(content))
        .route("/release-report", post(report_store))
        .route("/release-report/reasons", get(report_reasons))
        .route("/release-report/check", get(report_check))
        .route_layer(from_fn(guard::two_factor))
        .route_layer(from_fn(guard::verified))
        .route_layer(from_fn(guard::authenticated));
    Router::new()
        .merge(home)
        .merge(private)
        .route("/terms-and-conditions", get(terms).post(terms))
        .route("/privacy-policy", get(privacy).post(privacy))
        .route("/contact-us", get(contact_form).post(contact_submit))
        .route("/status", get(status))
}

/// Covers stay out of the session stack: they're public and cached for a year.
pub fn cover_router() -> Router<Ctx> {
    Router::new().route("/covers/{type}/{filename}", get(cover))
}

#[derive(Clone, Debug, FromRow)]
struct ContentRow {
    id: i64,
    title: String,
    body: Option<String>,
    metadescription: String,
    metakeywords: String,
}

struct ContentItem {
    id: i64,
    title: String,
    body: String,
    metadescription: String,
}

impl ContentItem {
    fn from_row(row: ContentRow) -> Self {
        let body = row.body.unwrap_or_default();
        Self {
            id: row.id,
            title: row.title,
            body: resolve_entities(body.trim_matches(|c| c == '\'' || c == '"')),
            metadescription: row.metadescription,
        }
    }

    fn title_or_untitled(&self) -> &str {
        if self.title.is_empty() {
            "Untitled"
        } else {
            &self.title
        }
    }

    /// `Str::limit(..., 150)`
    fn short_description(&self) -> String {
        if self.metadescription.chars().count() <= 150 {
            return self.metadescription.clone();
        }
        let cut: String = self.metadescription.chars().take(150).collect();
        format!("{}...", cut.trim_end())
    }
}

#[derive(Template)]
#[template(path = "pages/content.html")]
struct ContentPage {
    layout: Layout,
    front: bool,
    content: Vec<ContentItem>,
    header_title: String,
    header_eyebrow: &'static str,
    header_description: &'static str,
}

const CONTENT_COLUMNS: &str =
    "CAST(id AS SIGNED) AS id, title, body, metadescription, metakeywords";
const ORDERED: &str = "ORDER BY contenttype, COALESCE(ordinal, 1000000) ASC, id ASC";

async fn content(
    State(ctx): State<Ctx>,
    layout: Layout,
    input: Input,
    MaybeUser(signed_in): MaybeUser,
) -> WebResult {
    let role = match &signed_in {
        None => ROLE_EVERYONE,
        Some(auth) if auth.access.has_role("Admin") => ROLE_ADMIN,
        Some(auth) if auth.access.has_role("Moderator") => ROLE_MODERATOR,
        Some(_) => ROLE_LOGGED_IN,
    };
    let id = input.int("id", 0);
    let page = input.str("page");
    let staff = matches!(role, ROLE_ADMIN | ROLE_MODERATOR);
    let visible = if staff {
        "1 = 1"
    } else {
        "(role = ? OR role = ?)"
    };
    let (rows, front): (Vec<ContentRow>, bool) = if id == 0 && page == "content" {
        // Laravel listed every page here, staff-only and disabled ones included.
        let sql = format!(
            "SELECT {CONTENT_COLUMNS} FROM content WHERE id <> 1 AND status = 1 AND {visible} {ORDERED}"
        );
        let mut query = sqlx::query_as(sqlx::AssertSqlSafe(sql));
        if !staff {
            query = query.bind(ROLE_EVERYONE).bind(role);
        }
        (query.fetch_all(&ctx.db).await?, false)
    } else if id != 0 && !page.is_empty() {
        let sql =
            format!("SELECT {CONTENT_COLUMNS} FROM content WHERE id = ? AND {visible} LIMIT 1");
        let mut query = sqlx::query_as(sqlx::AssertSqlSafe(sql)).bind(id);
        if !staff {
            query = query.bind(ROLE_EVERYONE).bind(role);
        }
        (query.fetch_all(&ctx.db).await?, false)
    } else {
        // Laravel showed staff-only home rows to everyone. Their own pages already hid them.
        let sql = format!(
            "SELECT {CONTENT_COLUMNS} FROM content WHERE status = 1 AND contenttype = ? AND {visible} {ORDERED}"
        );
        let mut query = sqlx::query_as(sqlx::AssertSqlSafe(sql)).bind(TYPE_INDEX);
        if !staff {
            query = query.bind(ROLE_EVERYONE).bind(role);
        }
        (query.fetch_all(&ctx.db).await?, true)
    };
    if rows.is_empty() {
        return Ok((
            StatusCode::NOT_FOUND,
            Json(json!({"message": "There is nothing to see here, no content provided."})),
        )
            .into_response());
    }
    let layout = if front {
        let first = &rows[0];
        let title = if first.title.is_empty() {
            "Contents page"
        } else {
            first.title.as_str()
        };
        layout.titled(
            title,
            first.metakeywords.clone(),
            first.metadescription.clone(),
        )
    } else {
        layout.titled("Contents page", "contents", "This is the contents page.")
    };
    let page = ContentPage {
        header_title: if front {
            layout.username.clone()
        } else {
            "Content".to_owned()
        },
        header_eyebrow: if front { "Welcome" } else { "Explore the site" },
        header_description: if front {
            "Discover the latest site information and start exploring the index."
        } else {
            "Browse our published information and useful content pages."
        },
        layout,
        front,
        content: rows.into_iter().map(ContentItem::from_row).collect(),
    };
    Ok(Html(page.render()?).into_response())
}

#[derive(Template)]
#[template(path = "pages/terms.html")]
struct TermsPage {
    layout: Layout,
    last_updated: String,
}

/// Both legal pages print today's date as their last update, as the Blade views did.
fn today() -> String {
    Utc::now().format("%B %-d, %Y").to_string()
}

async fn terms(layout: Layout) -> WebResult {
    let app = config::app_name();
    let layout = layout.titled(
        format!("{app} - Terms and conditions"),
        "terms,conditions",
        format!("Terms and Conditions for {app}"),
    );
    Ok(Html(
        TermsPage {
            layout,
            last_updated: today(),
        }
        .render()?,
    )
    .into_response())
}

#[derive(Template)]
#[template(path = "pages/privacy.html")]
struct PrivacyPage {
    layout: Layout,
    last_updated: String,
}

async fn privacy(layout: Layout) -> WebResult {
    let app = config::app_name();
    let layout = layout.titled(
        format!("{app} - Privacy Policy"),
        "privacy,policy,data protection",
        format!("Privacy Policy for {app}"),
    );
    Ok(Html(
        PrivacyPage {
            layout,
            last_updated: today(),
        }
        .render()?,
    )
    .into_response())
}

#[derive(Template)]
#[template(path = "pages/contact.html")]
struct ContactPage {
    layout: Layout,
    sent: bool,
    errors: Vec<String>,
    old_username: String,
    old_useremail: String,
    old_comment: String,
    error_username: Option<String>,
    error_useremail: Option<String>,
    error_comment: Option<String>,
    error_captcha: Option<String>,
    captcha: Option<Captcha>,
}

fn contact_page(layout: Layout, session: &Session, sent: bool) -> WebResult {
    let app = config::app_name();
    let captcha = Captcha::from_env();
    let error_captcha = captcha
        .as_ref()
        .and_then(|captcha| session.error(captcha.field()));
    let page = ContactPage {
        layout: layout.titled(
            format!("Contact {app}"),
            "contact us,contact,get in touch,email",
            format!("Contact us at {app} and submit your feedback"),
        ),
        sent,
        errors: session.errors(),
        old_username: session.old("username"),
        old_useremail: session.old("useremail"),
        old_comment: session.old("comment"),
        error_username: session.error("username"),
        error_useremail: session.error("useremail"),
        error_comment: session.error("comment"),
        error_captcha,
        captcha,
    };
    Ok(Html(page.render()?).into_response())
}

async fn contact_form(layout: Layout, session: Session) -> WebResult {
    contact_page(layout, &session, false)
}

fn contact_errors(username: &str, email: &str, comment: &str) -> Vec<(&'static str, String)> {
    let mut errors = Vec::new();
    if username.is_empty() {
        errors.push(("username", "Please enter your name.".to_owned()));
    } else if username.chars().count() > 255 {
        errors.push((
            "username",
            "The username field must not be greater than 255 characters.".to_owned(),
        ));
    }
    if email.is_empty() {
        errors.push(("useremail", "Please enter your email address.".to_owned()));
    } else if !validate::valid_email(email) {
        errors.push((
            "useremail",
            "Please enter a valid email address.".to_owned(),
        ));
    } else if email.chars().count() > 255 {
        errors.push((
            "useremail",
            "The useremail field must not be greater than 255 characters.".to_owned(),
        ));
    }
    if comment.is_empty() {
        errors.push(("comment", "Please enter your message.".to_owned()));
    } else if comment.chars().count() < 10 {
        errors.push((
            "comment",
            "Your message must be at least 10 characters long.".to_owned(),
        ));
    }
    errors
}

async fn contact_submit(
    State(ctx): State<Ctx>,
    layout: Layout,
    session: Session,
    input: Input,
    ClientIp(ip): ClientIp,
) -> WebResult {
    let username = input.str("username");
    let email = input.str("useremail");
    let comment = input.str("comment");
    let mut errors = contact_errors(&username, &email, &comment);
    if let Some(error) = captcha_error(
        &input,
        &ip.to_string(),
        "Please complete the human verification.",
    )
    .await
    {
        errors.push(error);
    }
    if !errors.is_empty() {
        session.flash_errors(&errors);
        session.flash_input(&[
            ("username", username),
            ("useremail", email),
            ("comment", comment),
        ]);
        return Ok(Redirect::to("/contact-us").into_response());
    }
    if let Some(mailbox) = mailer::site_mailbox() {
        let body = format!(
            "Values submitted from contact form: username : {username}\nuseremail : {email}\ncomment : {comment}"
        );
        mailer::send_contact(&ctx, &mailbox, &email, &body).await?;
    }
    contact_page(layout, &session, true)
}

/// A health value from `service_statuses`, unknown text reading as operational.
#[derive(Clone, Copy, Debug, PartialEq, Eq)]
enum Health {
    Operational,
    Degraded,
    PartialOutage,
    MajorOutage,
    Maintenance,
}

const BADGE: &str = "px-3 py-1 inline-flex text-xs leading-5 font-semibold rounded-full";

impl Health {
    fn parse(value: &str) -> Self {
        match value {
            "degraded" => Self::Degraded,
            "partial_outage" => Self::PartialOutage,
            "major_outage" => Self::MajorOutage,
            "maintenance" => Self::Maintenance,
            _ => Self::Operational,
        }
    }

    fn label(self) -> &'static str {
        match self {
            Self::Operational => "Operational",
            Self::Degraded => "Degraded",
            Self::PartialOutage => "Partial outage",
            Self::MajorOutage => "Major outage",
            Self::Maintenance => "Maintenance",
        }
    }

    /// `ServiceStatusEnum::severity`: outages outrank maintenance, which outranks degraded.
    fn severity(self) -> u8 {
        match self {
            Self::Operational => 0,
            Self::Degraded => 1,
            Self::Maintenance => 2,
            Self::PartialOutage => 3,
            Self::MajorOutage => 4,
        }
    }

    fn banner(self) -> &'static str {
        match self {
            Self::Operational => {
                "border-green-500/50 bg-green-600 text-white dark:bg-green-900 dark:text-green-200"
            }
            Self::Degraded => {
                "border-yellow-500/50 bg-yellow-500 text-yellow-950 dark:bg-yellow-900 dark:text-yellow-200"
            }
            Self::Maintenance => {
                "border-blue-500/50 bg-blue-600 text-white dark:bg-blue-900 dark:text-blue-200"
            }
            Self::PartialOutage => {
                "border-orange-500/50 bg-orange-600 text-white dark:bg-orange-900 dark:text-orange-200"
            }
            Self::MajorOutage => {
                "border-red-500/50 bg-red-600 text-white dark:bg-red-900 dark:text-red-200"
            }
        }
    }

    fn badge(self) -> String {
        let colors = match self {
            Self::Operational => {
                "bg-green-100 dark:bg-green-900 text-green-800 dark:text-green-200"
            }
            Self::Degraded => {
                "bg-yellow-100 dark:bg-yellow-900 text-yellow-800 dark:text-yellow-200"
            }
            Self::Maintenance => "bg-blue-100 dark:bg-blue-900 text-blue-800 dark:text-blue-200",
            Self::PartialOutage => {
                "bg-orange-100 dark:bg-orange-900 text-orange-800 dark:text-orange-200"
            }
            Self::MajorOutage => "bg-red-100 dark:bg-red-900 text-red-800 dark:text-red-200",
        };
        format!("{BADGE} {colors}")
    }
}

struct ServiceView {
    name: String,
    uptime: String,
    response_time_ms: Option<u32>,
    checked_ago: String,
    status: Health,
}

struct IncidentView {
    title: String,
    description: String,
    impact: String,
    status: String,
    services: String,
    started: String,
    resolved: String,
}

const SMALL_BADGE: &str = "px-2 py-0.5 inline-flex text-xs leading-5 font-semibold rounded-full";

impl IncidentView {
    fn impact_label(&self) -> &'static str {
        match self.impact.as_str() {
            "minor" => "Minor",
            "major" => "Major",
            "critical" => "Critical",
            _ => "None",
        }
    }

    fn impact_badge(&self) -> String {
        let colors = match self.impact.as_str() {
            "minor" => "bg-yellow-100 dark:bg-yellow-900 text-yellow-800 dark:text-yellow-200",
            "major" => "bg-orange-100 dark:bg-orange-900 text-orange-800 dark:text-orange-200",
            "critical" => "bg-red-100 dark:bg-red-900 text-red-800 dark:text-red-200",
            _ => "bg-gray-100 dark:bg-gray-700 text-gray-800 dark:text-gray-200",
        };
        format!("{SMALL_BADGE} {colors}")
    }

    fn status_label(&self) -> &'static str {
        match self.status.as_str() {
            "identified" => "Identified",
            "monitoring" => "Monitoring",
            "resolved" => "Resolved",
            _ => "Investigating",
        }
    }

    fn status_badge(&self) -> String {
        let colors = match self.status.as_str() {
            "identified" => "bg-blue-100 dark:bg-blue-900 text-blue-800 dark:text-blue-200",
            "monitoring" => "bg-cyan-100 dark:bg-cyan-900 text-cyan-800 dark:text-cyan-200",
            "resolved" => "bg-green-100 dark:bg-green-900 text-green-800 dark:text-green-200",
            _ => "bg-purple-100 dark:bg-purple-900 text-purple-800 dark:text-purple-200",
        };
        format!("{SMALL_BADGE} {colors}")
    }

    fn services_or_dash(&self) -> &str {
        if self.services.is_empty() {
            "—"
        } else {
            &self.services
        }
    }
}

struct ResolvedDay {
    heading: String,
    incidents: Vec<IncidentView>,
}

#[derive(Template)]
#[template(path = "pages/status.html")]
struct StatusPage {
    layout: Layout,
    overall: Health,
    services: Vec<ServiceView>,
    active: Vec<IncidentView>,
    resolved: Vec<ResolvedDay>,
}

/// Carbon's `diffForHumans`, as far as a status page needs.
fn time_ago(then: DateTime<Utc>, now: DateTime<Utc>) -> String {
    let seconds = (now - then).num_seconds().max(0);
    let (count, unit) = match seconds {
        0..60 => (seconds, "second"),
        60..3600 => (seconds / 60, "minute"),
        3600..86400 => (seconds / 3600, "hour"),
        86400..2_592_000 => (seconds / 86400, "day"),
        2_592_000..31_536_000 => (seconds / 2_592_000, "month"),
        _ => (seconds / 31_536_000, "year"),
    };
    let plural = if count == 1 { "" } else { "s" };
    format!("{count} {unit}{plural} ago")
}

fn stamp(at: DateTime<Utc>) -> String {
    at.format("%b %-d, %Y %-I:%M %p UTC").to_string()
}

async fn load_incidents(ctx: &Ctx, condition: &str, order: &str) -> Result<Vec<IncidentView>> {
    // Only incidents touching a public (HTTP) service: internal probe outages stay private.
    let sql = format!(
        "SELECT i.title, i.description, i.status, i.impact, i.started_at, i.resolved_at,
                GROUP_CONCAT(IF(s.check_type = 'http', s.name, NULL) ORDER BY s.sort_order, s.id SEPARATOR ', ') AS services
         FROM service_incidents i
         JOIN service_incident_service_status p ON p.service_incident_id = i.id
         JOIN service_statuses s ON s.id = p.service_status_id
         WHERE {condition}
         GROUP BY i.id
         HAVING SUM(s.check_type = 'http') > 0
         ORDER BY {order}"
    );
    type Row = (
        String,
        String,
        String,
        String,
        DateTime<Utc>,
        Option<DateTime<Utc>>,
        Option<String>,
    );
    let rows: Vec<Row> = sqlx::query_as(sqlx::AssertSqlSafe(sql))
        .fetch_all(&ctx.db)
        .await?;
    Ok(rows
        .into_iter()
        .map(
            |(title, description, status, impact, started, resolved, services)| IncidentView {
                title,
                description,
                status,
                impact,
                services: services.unwrap_or_default(),
                started: stamp(started),
                resolved: resolved.map(stamp).unwrap_or_default(),
            },
        )
        .collect())
}

type ServiceRow = (String, String, String, Option<u32>, Option<DateTime<Utc>>);

async fn status(State(ctx): State<Ctx>, layout: Layout) -> WebResult {
    let now = Utc::now();
    let rows: Vec<ServiceRow> = sqlx::query_as(
        "SELECT name, status, CAST(uptime_percentage AS CHAR), response_time_ms, last_checked_at
         FROM service_statuses WHERE is_enabled = 1 AND check_type = 'http' ORDER BY sort_order, id",
    )
    .fetch_all(&ctx.db)
    .await?;
    let services: Vec<ServiceView> = rows
        .into_iter()
        .map(
            |(name, health, uptime, response_time_ms, checked)| ServiceView {
                name,
                uptime,
                response_time_ms,
                checked_ago: checked.map(|at| time_ago(at, now)).unwrap_or_default(),
                status: Health::parse(&health),
            },
        )
        .collect();
    let overall = services
        .iter()
        .map(|service| service.status)
        .max_by_key(|status| status.severity())
        .unwrap_or(Health::Operational);
    let active = load_incidents(&ctx, "i.status <> 'resolved'", "i.started_at DESC").await?;
    let resolved_rows: Vec<(String, IncidentView)> = load_incidents(
        &ctx,
        "i.status = 'resolved' AND i.resolved_at IS NOT NULL AND i.resolved_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)",
        "i.resolved_at DESC",
    )
    .await?
    .into_iter()
    .map(|incident| (incident.resolved.split(" ").take(3).collect::<Vec<_>>().join(" "), incident))
    .collect();
    let mut resolved: Vec<ResolvedDay> = Vec::new();
    for (heading, incident) in resolved_rows {
        match resolved.last_mut() {
            Some(day) if day.heading == heading => day.incidents.push(incident),
            _ => resolved.push(ResolvedDay {
                heading,
                incidents: vec![incident],
            }),
        }
    }
    let layout = layout.titled(
        "Service status",
        "",
        "Current status of site services, incidents, and uptime.",
    );
    Ok(Html(
        StatusPage {
            layout,
            overall,
            services,
            active,
            resolved,
        }
        .render()?,
    )
    .into_response())
}

fn valid_cover_filename(filename: &str) -> bool {
    let Some((stem, extension)) = filename.rsplit_once('.') else {
        return false;
    };
    let mut chars = stem.chars();
    chars
        .next()
        .is_some_and(|first| first.is_ascii_alphanumeric())
        && chars.all(|c| c.is_ascii_alphanumeric() || c == '_' || c == '-')
        && matches!(
            extension.to_ascii_lowercase().as_str(),
            "webp" | "jpg" | "jpeg" | "png" | "gif"
        )
}

/// `-1-cover.jpg` and `0.webp` are ids of nothing: answer with the placeholder without looking.
fn invalid_numeric_cover(filename: &str) -> bool {
    let Some((stem, extension)) = filename.rsplit_once('.') else {
        return false;
    };
    if !matches!(
        extension.to_ascii_lowercase().as_str(),
        "webp" | "jpg" | "jpeg"
    ) {
        return false;
    }
    let number = stem.strip_suffix("-cover").unwrap_or(stem);
    let digits = number.strip_prefix('-').unwrap_or(number);
    if digits.is_empty() || !digits.bytes().all(|byte| byte.is_ascii_digit()) {
        return false;
    }
    number.parse::<i64>().map_or(true, |id| id <= 0)
}

/// Paths to try, in order. The first of each base name and extension that exists wins.
fn cover_candidates(kind: &str, filename: &str) -> Vec<(String, String)> {
    let (stem, extension) = filename.rsplit_once('.').unwrap_or((filename, ""));
    let mut bases = vec![stem.to_owned()];
    if matches!(kind, "preview" | "sample") && !stem.ends_with("_thumb") {
        bases.insert(0, format!("{stem}_thumb"));
    }
    if kind == "anime"
        && let Some(id) = stem.strip_suffix("-cover")
        && !id.is_empty()
        && id.bytes().all(|byte| byte.is_ascii_digit())
    {
        bases.push(id.to_owned());
    }
    let mut extensions = vec![extension.to_ascii_lowercase()];
    for fallback in ["webp", "jpg", "jpeg"] {
        if !extensions.iter().any(|known| known == fallback) {
            extensions.push(fallback.to_owned());
        }
    }
    bases
        .into_iter()
        .flat_map(|base| {
            extensions
                .iter()
                .map(move |extension| (base.clone(), extension.clone()))
        })
        .collect()
}

fn sniff_image(bytes: &[u8]) -> Option<&'static str> {
    match bytes {
        [0xFF, 0xD8, 0xFF, ..] => Some("image/jpeg"),
        [0x89, b'P', b'N', b'G', ..] => Some("image/png"),
        [b'G', b'I', b'F', b'8', ..] => Some("image/gif"),
        [
            b'R',
            b'I',
            b'F',
            b'F',
            _,
            _,
            _,
            _,
            b'W',
            b'E',
            b'B',
            b'P',
            ..,
        ] => Some("image/webp"),
        _ => None,
    }
}

async fn placeholder() -> Result<Response, WebError> {
    let path = public_dir().join("assets/images/no-cover.png");
    let bytes = tokio::fs::read(path)
        .await
        .map_err(|_| WebError::NotFound)?;
    Ok(([(header::CONTENT_TYPE, "image/png")], bytes).into_response())
}

async fn cover(Path((kind, filename)): Path<(String, String)>) -> WebResult {
    const KINDS: [&str; 13] = [
        "anime",
        "audio",
        "audiosample",
        "book",
        "console",
        "games",
        "movies",
        "music",
        "preview",
        "sample",
        "tvrage",
        "video",
        "tvshows",
    ];
    const NUMERIC: [&str; 6] = ["anime", "book", "console", "games", "music", "tvshows"];
    if !KINDS.contains(&kind.as_str()) || !valid_cover_filename(&filename) {
        return Err(WebError::NotFound);
    }
    if NUMERIC.contains(&kind.as_str()) && invalid_numeric_cover(&filename) {
        return placeholder().await;
    }
    let storage = PathBuf::from(var_or("NNTMUX_STORAGE_DIR", "storage")).join("covers");
    let public = public_dir().join("covers");
    for (base, extension) in cover_candidates(&kind, &filename) {
        for root in [&storage, &public] {
            let path = root.join(&kind).join(format!("{base}.{extension}"));
            if let Ok(bytes) = tokio::fs::read(&path).await {
                let Some(mime) = sniff_image(&bytes) else {
                    return Err(WebError::NotFound);
                };
                let mut response = bytes.into_response();
                let headers = response.headers_mut();
                headers.insert(header::CONTENT_TYPE, HeaderValue::from_static(mime));
                headers.insert(
                    header::CACHE_CONTROL,
                    HeaderValue::from_static("public, max-age=31536000"),
                );
                return Ok(response);
            }
        }
    }
    placeholder().await
}

const REPORT_REASONS: [(&str, &str); 7] = [
    ("duplicate", "Duplicate Release"),
    ("fake", "Fake/Malicious Content"),
    ("password", "Password Protected"),
    ("incomplete", "Incomplete/Corrupted"),
    ("wrong_category", "Wrong Category"),
    ("spam", "Spam/Advertisement"),
    ("other", "Other"),
];

fn validation_failed(field: &str, message: &str) -> Response {
    (
        StatusCode::UNPROCESSABLE_ENTITY,
        Json(json!({"message": message, "errors": {field: [message]}})),
    )
        .into_response()
}

async fn report_store(
    State(ctx): State<Ctx>,
    CurrentUser(signed_in): CurrentUser,
    input: Input,
) -> WebResult {
    let release = input.get("release_id");
    let Some(release_id) = release
        .as_deref()
        .and_then(|value| value.parse::<i64>().ok())
    else {
        let message = if release.is_none() {
            "The release id field is required."
        } else {
            "The release id field must be an integer."
        };
        return Ok(validation_failed("release_id", message));
    };
    let reason = input.str("reason");
    if reason.is_empty() {
        return Ok(validation_failed("reason", "The reason field is required."));
    }
    if reason.chars().count() > 255 {
        return Ok(validation_failed(
            "reason",
            "The reason field must not be greater than 255 characters.",
        ));
    }
    let description = input.get("description");
    if description
        .as_ref()
        .is_some_and(|text| text.chars().count() > 1000)
    {
        return Ok(validation_failed(
            "description",
            "The description field must not be greater than 1000 characters.",
        ));
    }

    let exists: i64 = sqlx::query_scalar("SELECT COUNT(*) FROM releases WHERE id = ?")
        .bind(release_id)
        .fetch_one(&ctx.db)
        .await?;
    if exists == 0 {
        return Ok((
            StatusCode::NOT_FOUND,
            Json(json!({"success": false, "message": "Release not found."})),
        )
            .into_response());
    }
    if has_reported(&ctx, release_id, signed_in.user.id).await? {
        return Ok((
            StatusCode::CONFLICT,
            Json(json!({"success": false, "message": "You have already reported this release."})),
        )
            .into_response());
    }
    sqlx::query(
        "INSERT INTO release_reports (releases_id, users_id, reason, description, status, created_at, updated_at)
         VALUES (?, ?, ?, ?, 'pending', NOW(), NOW())",
    )
    .bind(release_id)
    .bind(signed_in.user.id)
    .bind(&reason)
    .bind(&description)
    .execute(&ctx.db)
    .await?;
    Ok(Json(json!({"success": true, "message": "Report submitted successfully. Thank you for helping improve our content."})).into_response())
}

async fn has_reported(ctx: &Ctx, release_id: i64, user_id: i64) -> Result<bool> {
    let count: i64 = sqlx::query_scalar(
        "SELECT COUNT(*) FROM release_reports WHERE releases_id = ? AND users_id = ?",
    )
    .bind(release_id)
    .bind(user_id)
    .fetch_one(&ctx.db)
    .await?;
    Ok(count > 0)
}

async fn report_reasons() -> Response {
    let reasons: BTreeMap<&str, &str> = REPORT_REASONS.into_iter().collect();
    Json(json!({ "reasons": reasons })).into_response()
}

async fn report_check(
    State(ctx): State<Ctx>,
    CurrentUser(signed_in): CurrentUser,
    input: Input,
) -> WebResult {
    let release = input.get("release_id");
    let Some(release_id) = release
        .as_deref()
        .and_then(|value| value.parse::<i64>().ok())
    else {
        let message = if release.is_none() {
            "The release id field is required."
        } else {
            "The release id field must be an integer."
        };
        return Ok(validation_failed("release_id", message));
    };
    let reported = has_reported(&ctx, release_id, signed_in.user.id).await?;
    Ok(Json(json!({ "has_reported": reported })).into_response())
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn cover_filenames_refuse_traversal() {
        assert!(valid_cover_filename("12-cover.jpg"));
        assert!(valid_cover_filename("abc_DEF-1.WEBP"));
        assert!(!valid_cover_filename("../12.jpg"));
        assert!(!valid_cover_filename(".hidden.jpg"));
        assert!(!valid_cover_filename("a.b.jpg"));
        assert!(!valid_cover_filename("a.txt"));
        assert!(!valid_cover_filename("-1.jpg"), "starts with a dash");
    }

    #[test]
    fn non_positive_ids_are_placeholders() {
        assert!(invalid_numeric_cover("0.jpg"));
        assert!(invalid_numeric_cover("0-cover.webp"));
        assert!(!invalid_numeric_cover("12-cover.jpg"));
        assert!(!invalid_numeric_cover("abc.jpg"));
        assert!(
            !invalid_numeric_cover("0.png"),
            "PHP's pattern covers webp and jpeg only"
        );
    }

    #[test]
    fn candidates_follow_the_php_search_order() {
        let previews = cover_candidates("preview", "abc.jpg");
        assert_eq!(previews[0], ("abc_thumb".to_owned(), "jpg".to_owned()));
        assert_eq!(previews[1], ("abc_thumb".to_owned(), "webp".to_owned()));
        assert!(previews.contains(&("abc".to_owned(), "jpeg".to_owned())));
        let anime = cover_candidates("anime", "12-cover.jpg");
        assert_eq!(anime.last().unwrap(), &("12".to_owned(), "jpeg".to_owned()));
    }

    #[test]
    fn images_are_sniffed_by_content() {
        assert_eq!(sniff_image(&[0xFF, 0xD8, 0xFF, 0xE0]), Some("image/jpeg"));
        assert_eq!(sniff_image(b"RIFF\0\0\0\0WEBPVP8 "), Some("image/webp"));
        assert_eq!(sniff_image(b"<html>"), None);
    }

    #[test]
    fn the_worst_status_wins_and_times_read_naturally() {
        assert!(Health::MajorOutage.severity() > Health::Maintenance.severity());
        assert_eq!(Health::parse("nonsense"), Health::Operational);
        let now = Utc::now();
        assert_eq!(
            time_ago(now - chrono::Duration::minutes(1), now),
            "1 minute ago"
        );
        assert_eq!(
            time_ago(now - chrono::Duration::hours(5), now),
            "5 hours ago"
        );
    }

    #[test]
    fn contact_errors_name_each_field() {
        assert!(contact_errors("name", "a@example.com", "a long enough note").is_empty());
        let errors = contact_errors("", "bad", "short");
        assert_eq!(errors.len(), 3);
        assert_eq!(
            errors[2].1,
            "Your message must be at least 10 characters long."
        );
    }
}
