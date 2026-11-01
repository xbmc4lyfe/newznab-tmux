//! Sending and tracking invitations, and the public invitation page.

use anyhow::Result;
use askama::Template;
use axum::extract::{Path, State};
use axum::http::{HeaderMap, StatusCode};
use axum::middleware::from_fn;
use axum::response::{Html, IntoResponse, Redirect, Response};
use axum::routing::{delete, get, post};
use axum::{Json, Router};
use chrono::{DateTime, Duration, Utc};
use nntmux_runtime::Ctx;
use nntmux_webkit::session::random_string;
use nntmux_webkit::{
    CurrentUser, Input, Layout, Pager, Session, User, WebError, WebResult, config, guard,
};
use serde::Serialize;
use serde_json::json;

use crate::auth::{back, flash_form, registration_status};
use crate::{mailer, validate};

const DEFAULT_EXPIRY_DAYS: i64 = 7;
const PER_PAGE: u64 = 15;

pub fn router() -> Router<Ctx> {
    let private = Router::new()
        .route("/invitations", get(index))
        .route("/invitations/create", get(create))
        .route("/invitations/store", post(store))
        .route("/invitations/{id}/resend", post(resend))
        .route("/invitations/{id}", delete(destroy))
        .route("/invitations/stats", get(stats))
        .route_layer(from_fn(guard::two_factor))
        .route_layer(from_fn(guard::authenticated));
    let ajax = Router::new()
        .route("/ajax_profile", post(ajax_invite))
        .route_layer(from_fn(guard::two_factor))
        .route_layer(from_fn(guard::verified))
        .route_layer(from_fn(guard::authenticated));
    Router::new()
        .merge(private)
        .merge(ajax)
        .route("/invitation/{token}", get(show))
}

#[derive(Default, Serialize)]
struct Stats {
    sent: i64,
    pending: i64,
    used: i64,
    expired: i64,
}

const VALID: &str = "is_active = 1 AND expires_at > NOW() AND used_at IS NULL";

async fn load_stats(ctx: &Ctx, user_id: i64) -> Result<Stats> {
    let sql = format!(
        "SELECT CAST(COUNT(*) AS SIGNED),
                CAST(COALESCE(SUM({VALID}), 0) AS SIGNED),
                CAST(COALESCE(SUM(used_at IS NOT NULL), 0) AS SIGNED),
                CAST(COALESCE(SUM(expires_at <= NOW() AND used_at IS NULL), 0) AS SIGNED)
         FROM invitations WHERE invited_by = ?"
    );
    let (sent, pending, used, expired): (i64, i64, i64, i64) =
        sqlx::query_as(sqlx::AssertSqlSafe(sql))
            .bind(user_id)
            .fetch_one(&ctx.db)
            .await?;
    Ok(Stats {
        sent,
        pending,
        used,
        expired,
    })
}

struct InvitationRow {
    id: i64,
    email: String,
    active: bool,
    expired: bool,
    created_date: String,
    created_time: String,
    expires_date: String,
    expires_time: String,
    used_date: String,
    used_time: String,
    used_by: String,
}

impl InvitationRow {
    fn can_act(&self) -> bool {
        self.used_date.is_empty() && !self.expired && self.active
    }
}

fn date(at: Option<DateTime<Utc>>) -> String {
    at.map(|at| at.format("%b %-d, %Y").to_string())
        .unwrap_or_default()
}

fn time(at: Option<DateTime<Utc>>) -> String {
    at.map(|at| at.format("%H:%M").to_string())
        .unwrap_or_default()
}

#[derive(Template)]
#[template(path = "invitations/index.html")]
struct IndexPage {
    layout: Layout,
    invite_mode: bool,
    header_title: &'static str,
    header_description: &'static str,
    status: String,
    stats: Stats,
    invitations: Vec<InvitationRow>,
    pager: Option<Pager>,
}

fn status_filter(status: &str) -> &'static str {
    match status {
        "valid" => "AND i.is_active = 1 AND i.expires_at > NOW() AND i.used_at IS NULL",
        "used" => "AND i.used_at IS NOT NULL",
        "expired" => "AND i.expires_at <= NOW() AND i.used_at IS NULL",
        "pending" => "AND i.used_at IS NULL",
        _ => "",
    }
}

const DISABLED_TITLE: &str = "Invitations Disabled";

async fn index(
    State(ctx): State<Ctx>,
    layout: Layout,
    input: Input,
    CurrentUser(signed_in): CurrentUser,
) -> WebResult {
    let status = input.str("status");
    let registration = registration_status(&ctx).await?;
    if !registration.is_invite_only {
        let page = IndexPage {
            layout: layout.titled(
                DISABLED_TITLE,
                "invitations,disabled",
                "Invitations are currently disabled on this site.",
            ),
            invite_mode: false,
            header_title: "Invitations disabled",
            header_description: "Invitations are currently disabled on this site.",
            status,
            stats: Stats::default(),
            invitations: Vec::new(),
            pager: None,
        };
        return Ok(Html(page.render()?).into_response());
    }
    let user_id = signed_in.user.id;
    let filter = status_filter(&status);
    let total: i64 = sqlx::query_scalar(sqlx::AssertSqlSafe(format!(
        "SELECT COUNT(*) FROM invitations i WHERE i.invited_by = ? {filter}"
    )))
    .bind(user_id)
    .fetch_one(&ctx.db)
    .await?;
    let pager = Pager::new(
        "/invitations",
        &input.query_pairs(),
        total.max(0) as u64,
        PER_PAGE,
        input.page(),
    );
    type Row = (
        i64,
        String,
        i8,
        i64,
        Option<DateTime<Utc>>,
        DateTime<Utc>,
        Option<DateTime<Utc>>,
        Option<String>,
        Option<String>,
    );
    let rows: Vec<Row> = sqlx::query_as(sqlx::AssertSqlSafe(format!(
        "SELECT CAST(i.id AS SIGNED), i.email, i.is_active, CAST(i.expires_at <= NOW() AS SIGNED), i.created_at, i.expires_at, i.used_at, u.username, u.email
         FROM invitations i LEFT JOIN users u ON u.id = i.used_by
         WHERE i.invited_by = ? {filter} ORDER BY i.created_at DESC LIMIT ? OFFSET ?"
    )))
    .bind(user_id)
    .bind(PER_PAGE)
    .bind(pager.offset())
    .fetch_all(&ctx.db)
    .await?;
    let invitations = rows
        .into_iter()
        .map(
            |(id, email, active, expired, created, expires, used, username, used_email)| {
                InvitationRow {
                    id,
                    email,
                    active: active != 0,
                    expired: expired != 0,
                    created_date: date(created),
                    created_time: time(created),
                    expires_date: date(Some(expires)),
                    expires_time: time(Some(expires)),
                    used_date: date(used),
                    used_time: time(used),
                    used_by: username.or(used_email).unwrap_or_default(),
                }
            },
        )
        .collect();
    let page = IndexPage {
        layout: layout.titled(
            "My Invitations",
            "invitations,invite,users,manage",
            "Manage your sent invitations and send new invitations to friends",
        ),
        invite_mode: true,
        header_title: "My invitations",
        header_description: "Send invitations and track who has joined.",
        status,
        stats: load_stats(&ctx, user_id).await?,
        invitations,
        pager: pager.has_pages().then_some(pager),
    };
    Ok(Html(page.render()?).into_response())
}

#[derive(Template)]
#[template(path = "invitations/create.html")]
struct CreatePage {
    layout: Layout,
    invite_mode: bool,
    header_title: &'static str,
    header_description: &'static str,
    invites_left: i64,
    errors: Vec<String>,
    old_email: String,
    old_expiry: String,
    error_email: Option<String>,
    error_expiry_days: Option<String>,
}

async fn pending_count(ctx: &Ctx, user_id: i64) -> Result<i64> {
    Ok(sqlx::query_scalar(sqlx::AssertSqlSafe(format!(
        "SELECT COUNT(*) FROM invitations WHERE invited_by = ? AND {VALID}"
    )))
    .bind(user_id)
    .fetch_one(&ctx.db)
    .await?)
}

async fn create(
    State(ctx): State<Ctx>,
    layout: Layout,
    session: Session,
    CurrentUser(signed_in): CurrentUser,
) -> WebResult {
    let invite_mode = registration_status(&ctx).await?.is_invite_only;
    let (layout, header_title, header_description) = if invite_mode {
        (
            layout.titled(
                "Send New Invitation",
                "invitation,invite,send,new,user",
                "Send a new invitation to invite someone to join the site",
            ),
            "Send an invitation",
            "Invite someone new to join the site.",
        )
    } else {
        (
            layout.titled(
                DISABLED_TITLE,
                "invitations,disabled",
                "Invitations are currently disabled on this site.",
            ),
            "Invitations disabled",
            "Invitations are currently disabled on this site.",
        )
    };
    let pending = if invite_mode {
        pending_count(&ctx, signed_in.user.id).await?
    } else {
        0
    };
    let total = i64::from(signed_in.user.invites);
    let page = CreatePage {
        layout,
        invite_mode,
        header_title,
        header_description,
        invites_left: total - pending,
        errors: session.errors(),
        old_email: session.old("email"),
        old_expiry: session.old("expiry_days"),
        error_email: session.error("email"),
        error_expiry_days: session.error("expiry_days"),
    };
    Ok(Html(page.render()?).into_response())
}

fn disabled(session: &Session) -> Response {
    session.flash("error", "Invitations are currently disabled.");
    Redirect::to("/invitations").into_response()
}

/// Sends the invitation, or says why not in the words the form shows.
async fn create_and_send(ctx: &Ctx, inviter: &User, email: &str, days: i64) -> Result<(), String> {
    let pending = pending_count(ctx, inviter.id)
        .await
        .map_err(|failure| failure.to_string())?;
    if i64::from(inviter.invites) - pending <= 0 {
        return Err(format!(
            "You have no invitations available. You have {pending} pending invitation(s) out of {} total.",
            inviter.invites
        ));
    }
    let email = email.trim().to_lowercase();
    let result: Result<Option<String>> = async {
        let existing: i64 = sqlx::query_scalar(sqlx::AssertSqlSafe(format!(
            "SELECT COUNT(*) FROM invitations WHERE email = ? AND {VALID}"
        )))
        .bind(&email)
        .fetch_one(&ctx.db)
        .await?;
        if existing > 0 {
            return Ok(Some("A valid invitation already exists for this email address.".to_owned()));
        }
        let token = random_string(64);
        let expires = Utc::now() + Duration::days(days);
        sqlx::query(
            "INSERT INTO invitations (token, email, invited_by, expires_at, is_active, metadata, created_at, updated_at)
             VALUES (?, ?, ?, ?, 1, '[]', NOW(), NOW())",
        )
        .bind(&token)
        .bind(&email)
        .bind(inviter.id)
        .bind(expires)
        .execute(&ctx.db)
        .await?;
        send_invitation(ctx, &email, &inviter.username, &token, expires).await?;
        Ok(None)
    }
    .await;
    match result {
        Ok(None) => Ok(()),
        Ok(Some(message)) => Err(message),
        Err(failure) => Err(failure.to_string()),
    }
}

async fn send_invitation(
    ctx: &Ctx,
    email: &str,
    inviter: &str,
    token: &str,
    expires: DateTime<Utc>,
) -> Result<()> {
    let url = format!("{}/register?token={token}", config::app_url());
    mailer::send_invitation(
        ctx,
        email,
        inviter,
        &url,
        &expires.format("%B %-d, %Y at %-I:%M %p").to_string(),
    )
    .await
}

async fn store(
    State(ctx): State<Ctx>,
    session: Session,
    input: Input,
    headers: HeaderMap,
    CurrentUser(signed_in): CurrentUser,
) -> WebResult {
    if !registration_status(&ctx).await?.is_invite_only {
        return Ok(disabled(&session));
    }
    let email = input.str("email");
    let days_text = input.str("expiry_days");
    let mut errors: Vec<(&str, String)> = Vec::new();
    if email.is_empty() {
        errors.push(("email", "The email field is required.".to_owned()));
    } else if !validate::valid_email(&email) {
        errors.push((
            "email",
            "The email field must be a valid email address.".to_owned(),
        ));
    } else {
        let taken: i64 = sqlx::query_scalar("SELECT COUNT(*) FROM users WHERE email = ?")
            .bind(&email)
            .fetch_one(&ctx.db)
            .await?;
        if taken > 0 {
            errors.push(("email", "The email has already been taken.".to_owned()));
        }
    }
    let days = if input.has("expiry_days") {
        days_text.parse::<i64>().ok()
    } else {
        Some(DEFAULT_EXPIRY_DAYS)
    };
    let days = match days {
        Some(days @ 1..=30) => days,
        _ => {
            errors.push((
                "expiry_days",
                "The expiry days field must be between 1 and 30.".to_owned(),
            ));
            DEFAULT_EXPIRY_DAYS
        }
    };
    if !errors.is_empty() {
        flash_form(
            &session,
            &errors,
            &[("email", email), ("expiry_days", days_text)],
        );
        return Ok(back(&headers, "/invitations/create"));
    }
    match create_and_send(&ctx, &signed_in.user, &email, days).await {
        Ok(()) => {
            session.flash(
                "success",
                format!("Invitation sent successfully to {email}"),
            );
            Ok(Redirect::to("/invitations").into_response())
        }
        Err(message) => {
            session.flash("error", message);
            session.flash_input(&[("email", email), ("expiry_days", days_text)]);
            Ok(back(&headers, "/invitations/create"))
        }
    }
}

/// `(email, token, expires, used, active)` for an invitation this user sent.
type Owned = (String, String, DateTime<Utc>, bool, bool);
type OwnedRow = (
    i64,
    String,
    String,
    DateTime<Utc>,
    Option<DateTime<Utc>>,
    i8,
);

async fn owned(ctx: &Ctx, id: i64, user_id: i64) -> Result<Owned, WebError> {
    let row: Option<OwnedRow> = sqlx::query_as(
        "SELECT CAST(invited_by AS SIGNED), email, token, expires_at, used_at, is_active FROM invitations WHERE id = ?",
    )
    .bind(id)
    .fetch_optional(&ctx.db)
    .await?;
    let (inviter, email, token, expires, used, active) = row.ok_or(WebError::NotFound)?;
    if inviter != user_id {
        return Err(WebError::Forbidden(Some("Unauthorized".to_owned())));
    }
    Ok((email, token, expires, used.is_some(), active != 0))
}

async fn resend(
    State(ctx): State<Ctx>,
    session: Session,
    Path(id): Path<i64>,
    headers: HeaderMap,
    CurrentUser(signed_in): CurrentUser,
) -> WebResult {
    if !registration_status(&ctx).await?.is_invite_only {
        return Ok(disabled(&session));
    }
    let (email, token, expires, used, active) = owned(&ctx, id, signed_in.user.id).await?;
    if used || !active || expires <= Utc::now() {
        session.flash("error", "Cannot resend an invalid invitation.");
        return Ok(back(&headers, "/invitations"));
    }
    if let Err(failure) =
        send_invitation(&ctx, &email, &signed_in.user.username, &token, expires).await
    {
        session.flash("error", failure.to_string());
        return Ok(back(&headers, "/invitations"));
    }
    session.flash("success", "Invitation resent successfully");
    Ok(Redirect::to("/invitations").into_response())
}

async fn destroy(
    State(ctx): State<Ctx>,
    session: Session,
    Path(id): Path<i64>,
    headers: HeaderMap,
    CurrentUser(signed_in): CurrentUser,
) -> WebResult {
    if !registration_status(&ctx).await?.is_invite_only {
        return Ok(disabled(&session));
    }
    let (_, _, _, used, _) = owned(&ctx, id, signed_in.user.id).await?;
    if used {
        session.flash("error", "Cannot cancel a used invitation.");
        return Ok(back(&headers, "/invitations"));
    }
    sqlx::query("UPDATE invitations SET is_active = 0, updated_at = NOW() WHERE id = ?")
        .bind(id)
        .execute(&ctx.db)
        .await?;
    session.flash("success", "Invitation cancelled successfully");
    Ok(Redirect::to("/invitations").into_response())
}

async fn stats(State(ctx): State<Ctx>, CurrentUser(signed_in): CurrentUser) -> WebResult {
    if !registration_status(&ctx).await?.is_invite_only {
        return Ok((
            StatusCode::NOT_FOUND,
            Json(json!({"message": "Invitations are disabled"})),
        )
            .into_response());
    }
    Ok(Json(load_stats(&ctx, signed_in.user.id).await?).into_response())
}

struct Preview {
    inviter: String,
    email: String,
    expires: String,
    expired: bool,
    used: bool,
}

#[derive(Template)]
#[template(path = "invitations/show.html")]
struct ShowPage {
    layout: Layout,
    header_title: String,
    preview: Option<Preview>,
    token: String,
    registration_open: bool,
    invite_only: bool,
    registration_message: String,
}

type PreviewRow = (String, DateTime<Utc>, Option<DateTime<Utc>>, Option<String>);

async fn show(State(ctx): State<Ctx>, layout: Layout, Path(token): Path<String>) -> WebResult {
    let row: Option<PreviewRow> = sqlx::query_as(
        "SELECT i.email, i.expires_at, i.used_at, u.username
         FROM invitations i LEFT JOIN users u ON u.id = i.invited_by WHERE i.token = ? LIMIT 1",
    )
    .bind(&token)
    .fetch_optional(&ctx.db)
    .await?;
    let preview = row.map(|(email, expires, used, inviter)| Preview {
        inviter: inviter.unwrap_or_else(|| "Unknown".to_owned()),
        email,
        expires: expires.format("%b %-d, %Y %H:%M").to_string(),
        expired: expires < Utc::now(),
        used: used.is_some(),
    });
    let registration = registration_status(&ctx).await?;
    let layout = if preview.is_some() {
        layout.titled(
            "Invitation to Join",
            "invitation,join,register,signup",
            "You have been invited to join our community",
        )
    } else {
        layout.titled(
            "Invalid Invitation",
            "invitation,join,register,signup",
            "This invitation link is invalid or expired",
        )
    };
    let page = ShowPage {
        header_title: format!("Invitation to join {}", layout.site.title),
        layout,
        preview,
        token,
        registration_open: registration.is_open,
        invite_only: registration.is_invite_only,
        registration_message: registration.message,
    };
    Ok(Html(page.render()?).into_response())
}

/// The old AJAX form, with its plain-text answer.
async fn ajax_invite(
    State(ctx): State<Ctx>,
    input: Input,
    CurrentUser(signed_in): CurrentUser,
) -> WebResult {
    let email = input.str("emailto");
    if input.int("action", 0) != 1 || !validate::valid_email(&email) {
        return Ok("Invite not sent.".into_response());
    }
    // Laravel skipped the quota here, which let anyone mint links. The same rules apply now.
    if create_and_send(&ctx, &signed_in.user, &email, DEFAULT_EXPIRY_DAYS)
        .await
        .is_err()
    {
        return Ok("Invite not sent.".into_response());
    }
    let token: Option<String> = sqlx::query_scalar(sqlx::AssertSqlSafe(format!(
        "SELECT token FROM invitations WHERE email = ? AND invited_by = ? AND {VALID} ORDER BY id DESC LIMIT 1"
    )))
    .bind(email.trim().to_lowercase())
    .bind(signed_in.user.id)
    .fetch_optional(&ctx.db)
    .await?;
    let Some(token) = token else {
        return Ok("Invite not sent.".into_response());
    };
    let url = format!("{}/register?token={token}", config::app_url());
    Ok(
        format!("Invite sent. Alternatively paste them following link to register - {url}")
            .into_response(),
    )
}
