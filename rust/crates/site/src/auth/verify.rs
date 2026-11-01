use anyhow::Result;
use askama::Template;
use axum::extract::{Path, State};
use axum::http::{HeaderMap, Uri};
use axum::response::{Html, IntoResponse, Redirect};
use chrono::Utc;
use nntmux_runtime::Ctx;
use nntmux_webkit::config::{self, constant_time_eq, hmac_hex};
use nntmux_webkit::{CurrentUser, MaybeUser, Session, WebError, WebResult};
use sha1::{Digest, Sha1};

use super::back;
use crate::guest::GuestLayout;
use crate::mailer;

const LINK_MINUTES: i64 = 60;

#[derive(Template)]
#[template(path = "auth/verify_email.html")]
struct NoticePage {
    layout: GuestLayout,
    resent: bool,
}

fn email_hash(email: &str) -> String {
    hex::encode(Sha1::digest(email.as_bytes()))
}

/// A signed link, the same shape as Laravel's `temporarySignedRoute` against `APP_URL`.
pub(crate) fn signed_link(path: &str, expires: i64) -> String {
    let unsigned = format!("{}{path}?expires={expires}", config::app_url());
    let signature = hmac_hex(&config::app_key(), &unsigned);
    format!("{unsigned}&signature={signature}")
}

fn link_is_valid(uri: &Uri, now: i64) -> bool {
    let mut expires = None;
    let mut signature = None;
    for pair in uri.query().unwrap_or_default().split('&') {
        match pair.split_once('=') {
            Some(("expires", value)) => expires = value.parse::<i64>().ok(),
            Some(("signature", value)) => signature = Some(value),
            _ => {}
        }
    }
    let (Some(expires), Some(signature)) = (expires, signature) else {
        return false;
    };
    let unsigned = format!("{}{}?expires={expires}", config::app_url(), uri.path());
    expires >= now && constant_time_eq(signature, &hmac_hex(&config::app_key(), &unsigned))
}

pub(crate) async fn send_verification(
    ctx: &Ctx,
    id: i64,
    email: &str,
    username: &str,
) -> Result<()> {
    let path = format!("/email/verify/{id}/{}", email_hash(email));
    let url = signed_link(&path, Utc::now().timestamp() + LINK_MINUTES * 60);
    mailer::send_verification(ctx, email, username, &url).await
}

pub async fn show(layout: GuestLayout, CurrentUser(signed_in): CurrentUser) -> WebResult {
    if signed_in.user.has_verified_email() {
        return Ok(Redirect::to("/").into_response());
    }
    let resent = layout.flashed("status").as_deref() == Some("resent");
    Ok(Html(NoticePage { layout, resent }.render()?).into_response())
}

pub async fn resend(
    State(ctx): State<Ctx>,
    session: Session,
    MaybeUser(signed_in): MaybeUser,
    headers: HeaderMap,
) -> WebResult {
    let Some(signed_in) = signed_in else {
        return Ok(Redirect::to("/login").into_response());
    };
    let user = &signed_in.user;
    if user.has_verified_email() {
        return Ok(Redirect::to("/").into_response());
    }
    send_verification(&ctx, user.id, &user.email, &user.username).await?;
    session.flash("status", "resent");
    Ok(back(&headers, "/email/verify"))
}

pub async fn confirm(
    State(ctx): State<Ctx>,
    session: Session,
    Path((id, hash)): Path<(i64, String)>,
    uri: Uri,
) -> WebResult {
    if !link_is_valid(&uri, Utc::now().timestamp()) {
        return Err(WebError::Forbidden(Some("Invalid signature.".to_owned())));
    }
    let row: Option<(String, i8, Option<chrono::DateTime<Utc>>)> = sqlx::query_as(
        "SELECT email, verified, email_verified_at FROM users WHERE id = ? AND deleted_at IS NULL",
    )
    .bind(id)
    .fetch_optional(&ctx.db)
    .await?;
    let (email, verified, verified_at) = row.ok_or(WebError::NotFound)?;
    if !constant_time_eq(&hash, &email_hash(&email)) {
        return Err(WebError::Forbidden(None));
    }
    if verified == 0 && verified_at.is_none() {
        sqlx::query("UPDATE users SET email_verified_at = NOW(), verified = 1, verification_token = NULL, updated_at = NOW() WHERE id = ?")
            .bind(id)
            .execute(&ctx.db)
            .await?;
    }
    session.flash(
        "success",
        "Your email address has been verified. You can now sign in.",
    );
    Ok(Redirect::to("/login").into_response())
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn signed_links_verify_and_reject_tampering() {
        let now = 1_000_000;
        let link = signed_link("/email/verify/7/abc", now + 60);
        let relative = link.strip_prefix(&config::app_url()).unwrap();
        assert!(link_is_valid(&relative.parse().unwrap(), now));
        assert!(
            !link_is_valid(&relative.parse().unwrap(), now + 61),
            "expired"
        );
        let tampered = relative.replace("/7/", "/8/");
        assert!(!link_is_valid(&tampered.parse().unwrap(), now));
        assert!(!link_is_valid(&"/email/verify/7/abc".parse().unwrap(), now));
    }
}
