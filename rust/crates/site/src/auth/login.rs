use askama::Template;
use axum::extract::State;
use axum::http::HeaderMap;
use axum::http::header::SET_COOKIE;
use axum::response::{Html, IntoResponse, Redirect, Response};
use nntmux_runtime::Ctx;
use nntmux_webkit::auth::{self, log_failed_login};
use nntmux_webkit::{
    ClientIp, Input, MaybeUser, Session, User, WebResult, config, guard, throttle,
};

use super::{captcha_error, two_factor};
use crate::guest::GuestLayout;
use crate::validate;

const GENERIC_FAILURE: &str =
    "Username or email and password combination used does not match our records!";
pub(crate) const BREACH_WARNING: &str = "Security Alert: Your password has been found in a data breach. We strongly recommend changing it immediately in your account settings.";
const MAX_ATTEMPTS: u64 = 3;
const DECAY_SECONDS: u64 = 120;

#[derive(Template)]
#[template(path = "auth/login.html")]
struct LoginPage {
    layout: GuestLayout,
}

pub async fn show(layout: GuestLayout) -> WebResult {
    Ok(Html(LoginPage { layout }.render()?).into_response())
}

fn attempts_key(username: &str, ip: &str) -> String {
    format!("nntmux:login_attempts:{}|{ip}", username.to_lowercase())
}

async fn failed(
    ctx: &Ctx,
    session: &Session,
    key: &str,
    username: &str,
    ip: &str,
    message: &str,
) -> Response {
    if let Err(failure) = throttle::hit(ctx, key, DECAY_SECONDS).await {
        tracing::warn!(error = %failure, "counting a failed login failed");
    }
    log_failed_login(username, ip);
    session.flash("error", message);
    Redirect::to("/login").into_response()
}

pub async fn submit(
    State(ctx): State<Ctx>,
    session: Session,
    input: Input,
    ClientIp(ip): ClientIp,
    headers: HeaderMap,
) -> WebResult {
    let ip = ip.to_string();
    if let Some((field, message)) = captcha_error(
        &input,
        &ip,
        "Please complete the Cloudflare human verification before signing in.",
    )
    .await
    {
        session.flash_errors(&[(field, message)]);
        return Ok(Redirect::to("/login").into_response());
    }

    let username = input.str("username");
    let password = input.str("password");
    let key = attempts_key(&username, &ip);
    if throttle::attempts(&ctx, &key).await >= MAX_ATTEMPTS {
        session.flash(
            "warning",
            "You have failed to log in too many times. Try again in 2 minutes.",
        );
        return Ok(Redirect::to("/login").into_response());
    }

    if username.is_empty() || password.is_empty() {
        let mut messages = String::new();
        if username.is_empty() {
            messages.push_str("The username field is required.");
        }
        if password.is_empty() {
            messages.push_str("The password field is required.");
        }
        return Ok(failed(&ctx, &session, &key, &username, &ip, &messages).await);
    }

    let column = if validate::valid_email(&username) {
        "email"
    } else {
        "username"
    };
    let found = User::find_by(&ctx.db, column, &username).await?;
    let Some(user) = found.filter(|user| auth::verify_password(&password, &user.password)) else {
        return Ok(failed(&ctx, &session, &key, &username, &ip, GENERIC_FAILURE).await);
    };
    if !user.eligible_for_authentication() {
        return Ok(failed(&ctx, &session, &key, &username, &ip, GENERIC_FAILURE).await);
    }

    session.regenerate();
    auth::log_if_failed(
        auth::record_login(&ctx, user.id, &ip).await,
        "recording the login",
    );
    let remember = input.boolean("rememberme") || input.boolean("remember");
    let breached = validate::is_breached(&password, 1).await;

    if two_factor::required(&ctx, user.id).await? {
        let trusted = match config::cookie_value(&headers, auth::TRUSTED_DEVICE_COOKIE) {
            Some(cookie) => {
                auth::trusted_device_valid(&ctx, &config::decode_component(&cookie), user.id)
                    .await?
            }
            None => false,
        };
        if !trusted {
            session.put("url.intended", "/");
            session.put("2fa:remember", remember);
            session.put("2fa:password_breached", breached);
            session.put("2fa:user:id", user.id);
            auth::clear_two_factor(&session);
            return Ok(Redirect::to("/2fa/verify").into_response());
        }
        auth::mark_two_factor_passed(&session);
        session.put("2fa:trusted_device", true);
    }

    auth::login(&session, &user);
    auth::rotate_session_token(&ctx, &session, user.id).await?;
    throttle::clear(&ctx, &key).await;
    session.flash("info", "You have been logged in");
    if breached {
        session.flash("warning", BREACH_WARNING);
    }
    let mut response = Redirect::to(&guard::pull_intended(&session, "/")).into_response();
    if remember {
        let cookie = auth::issue_remember_cookie(&ctx, &user).await?;
        response.headers_mut().append(SET_COOKIE, cookie.parse()?);
    }
    Ok(response)
}

pub async fn logout(
    State(ctx): State<Ctx>,
    session: Session,
    MaybeUser(signed_in): MaybeUser,
) -> WebResult {
    let clear = match signed_in {
        Some(auth) => Some(auth::revoke_remember(&ctx, auth.user.id).await?),
        None => None,
    };
    auth::logout(&session);
    session.invalidate();
    session.flash("success", "You have been logged out successfully");
    // The trusted-device cookie stays: signing out doesn't untrust the browser.
    let mut response = Redirect::to("/login").into_response();
    if let Some(cookie) = clear {
        response.headers_mut().append(SET_COOKIE, cookie.parse()?);
    }
    Ok(response)
}
