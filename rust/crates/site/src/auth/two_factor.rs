use std::time::{SystemTime, UNIX_EPOCH};

use anyhow::Result;
use askama::Template;
use axum::extract::State;
use axum::http::HeaderMap;
use axum::http::header::{SET_COOKIE, USER_AGENT};
use axum::response::{Html, IntoResponse, Redirect, Response};
use nntmux_runtime::Ctx;
use nntmux_webkit::{ClientIp, Input, MaybeUser, Session, User, WebResult, auth, guard};
use totp_rs::{Algorithm, Builder, Secret, Totp};

use super::login::BREACH_WARNING;
use crate::guest::GuestLayout;

#[derive(Template)]
#[template(path = "auth/two_factor_verify.html")]
struct VerifyPage {
    layout: GuestLayout,
}

/// The account's enabled secret, if 2FA is on for it.
pub(crate) async fn enabled_secret(ctx: &Ctx, user_id: i64) -> Result<Option<String>> {
    let row: Option<(i8, Option<String>)> = sqlx::query_as(
        "SELECT google2fa_enable, google2fa_secret FROM password_securities WHERE user_id = ? LIMIT 1",
    )
    .bind(user_id)
    .fetch_optional(&ctx.db)
    .await?;
    Ok(
        row.and_then(|(enabled, secret)| {
            secret.filter(|secret| enabled == 1 && !secret.is_empty())
        }),
    )
}

pub(crate) async fn required(ctx: &Ctx, user_id: i64) -> Result<bool> {
    Ok(enabled_secret(ctx, user_id).await?.is_some())
}

/// Google2FA's check: SHA-1, six digits, 30 seconds, one step either way. Laravel's secrets are
/// 16 base32 characters, shorter than RFC 6238 asks for, hence `build_noncompliant`.
fn totp(secret: &str) -> Option<Totp> {
    let secret = Secret::try_from_base32(secret).ok()?;
    Some(
        Builder::new()
            .with_algorithm(Algorithm::SHA1)
            .with_digits(6)
            .with_skew(1)
            .with_step_duration(30)
            .with_secret(secret)
            .build_noncompliant(),
    )
}

pub fn verify_code(secret: &str, code: &str, now: u64) -> bool {
    totp(secret).is_some_and(|totp| totp.check(code, now).is_some())
}

fn now() -> u64 {
    SystemTime::now()
        .duration_since(UNIX_EPOCH)
        .map_or(0, |elapsed| elapsed.as_secs())
}

fn challenged_user_id(session: &Session, signed_in: &Option<auth::Auth>) -> Option<i64> {
    signed_in
        .as_ref()
        .map(|auth| auth.user.id)
        .or_else(|| session.get_i64("2fa:user:id"))
}

fn to_login(session: &Session, message: &str) -> Response {
    session.forget("2fa:user:id");
    session.flash("error", message);
    Redirect::to("/login").into_response()
}

pub async fn show(
    State(ctx): State<Ctx>,
    layout: GuestLayout,
    session: Session,
    MaybeUser(signed_in): MaybeUser,
) -> WebResult {
    let enabled = match challenged_user_id(&session, &signed_in) {
        Some(id) => required(&ctx, id).await?,
        None => false,
    };
    if !enabled {
        return Ok(to_login(&session, "User not found. Please login again."));
    }
    Ok(Html(VerifyPage { layout }.render()?).into_response())
}

pub async fn submit(
    State(ctx): State<Ctx>,
    session: Session,
    input: Input,
    MaybeUser(signed_in): MaybeUser,
    ClientIp(ip): ClientIp,
    headers: HeaderMap,
) -> WebResult {
    let code = input.str("one_time_password");
    if code.is_empty() || !code.bytes().all(|byte| byte.is_ascii_digit()) {
        let message = if code.is_empty() {
            "The one time password field is required."
        } else {
            "The one time password field must be a number."
        };
        session.flash_errors(&[("one_time_password", message.to_owned())]);
        return Ok(Redirect::to("/2fa/verify").into_response());
    }

    let id = challenged_user_id(&session, &signed_in);
    let user = match id {
        Some(id) => User::find(&ctx.db, id).await?,
        None => None,
    };
    let secret = match &user {
        Some(user) => enabled_secret(&ctx, user.id).await?,
        None => None,
    };
    let (Some(user), Some(secret)) = (user, secret) else {
        return Ok(to_login(
            &session,
            "User not found or 2FA not configured. Please login again.",
        ));
    };

    if !verify_code(&secret, &code, now()) {
        session.flash("error", "Invalid authentication code. Please try again.");
        return Ok(Redirect::to("/2fa/verify").into_response());
    }

    let mut cookies = Vec::new();
    let mut breached = false;
    if signed_in.is_none() {
        auth::login(&session, &user);
        auth::rotate_session_token(&ctx, &session, user.id).await?;
        breached = session.get_bool("2fa:password_breached");
        if session.get_bool("2fa:remember") {
            cookies.push(auth::issue_remember_cookie(&ctx, &user).await?);
        }
    }
    session.regenerate();
    session.forget("2fa:trusted_device");
    auth::mark_two_factor_passed(&session);
    for key in ["2fa:user:id", "2fa:remember", "2fa:password_breached"] {
        session.forget(key);
    }

    session.flash(
        "success",
        "Two-factor authentication verified successfully.",
    );
    if breached {
        session.flash("warning", BREACH_WARNING);
    }
    if input.str("trust_device") == "1" {
        let agent = headers
            .get(USER_AGENT)
            .and_then(|value| value.to_str().ok());
        let cookie =
            auth::issue_trusted_device(&ctx, user.id, Some(&ip.to_string()), agent).await?;
        cookies.push(cookie);
    }
    let mut response = Redirect::to(&guard::pull_intended(&session, "/")).into_response();
    for cookie in cookies {
        response.headers_mut().append(SET_COOKIE, cookie.parse()?);
    }
    Ok(response)
}

#[cfg(test)]
mod tests {
    use super::*;

    /// RFC 6238's SHA-1 vector, with Google2FA's six digits, on an 80-bit base32 secret.
    #[test]
    fn codes_match_google2fa_and_allow_one_step_of_drift() {
        let secret = "GEZDGNBVGY3TQOJQ";
        let code = totp(secret).unwrap().generate(1_000_000_000).to_string();
        assert!(verify_code(secret, &code, 1_000_000_000));
        assert!(verify_code(secret, &code, 1_000_000_029));
        assert!(!verify_code(secret, &code, 1_000_000_090));
        assert!(!verify_code("not base32!", &code, 1_000_000_000));
    }
}
