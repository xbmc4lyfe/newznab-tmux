//! Who is signed in. Follows Laravel's guard: a session key for the login, an optional remember
//! cookie, a session that ends when the password changes, and one live session per account
//! through `users.session_token`.

use std::convert::Infallible;
use std::sync::Arc;

use anyhow::Result;
use axum::extract::{FromRequestParts, Request};
use axum::http::HeaderMap;
use axum::http::header::SET_COOKIE;
use axum::http::request::Parts;
use axum::middleware::Next;
use axum::response::{IntoResponse, Redirect, Response};
use chrono::Utc;
use nntmux_runtime::Ctx;
use serde::{Deserialize, Serialize};
use tracing::{error, warn};

use crate::client_ip;
use crate::config::{self, constant_time_eq, hmac_hex};
use crate::error::WebError;
use crate::guard;
use crate::session::{Session, random_string};
use crate::user::{Access, User};

const LOGIN_KEY: &str = "login_web";
const PASSWORD_KEY: &str = "password_hash_web";
const TOKEN_KEY: &str = "session_token_web";
const REMEMBER_COOKIE: &str = "remember_web";
/// Five years, Laravel's remember lifetime.
const REMEMBER_SECONDS: u64 = 5 * 365 * 24 * 3600;
pub const TRUSTED_DEVICE_COOKIE: &str = "2fa_trusted_device";
const TRUSTED_DEVICE_DAYS: i64 = 30;
const TWO_FACTOR_PASSED: &str = "google2fa.auth_passed";
const TWO_FACTOR_TIME: &str = "google2fa.auth_time";
const TWO_FACTOR_VIA_DEVICE: &str = "2fa:trusted_device";

/// The signed-in user, set by [`layer`] for this request.
#[derive(Clone)]
pub struct Auth {
    pub user: Arc<User>,
    pub access: Arc<Access>,
}

/// Extracts the signed-in user, or `None` for a guest.
pub struct MaybeUser(pub Option<Auth>);

impl<S: Sync> FromRequestParts<S> for MaybeUser {
    type Rejection = Infallible;

    async fn from_request_parts(parts: &mut Parts, _: &S) -> Result<Self, Self::Rejection> {
        Ok(Self(parts.extensions.get::<Auth>().cloned()))
    }
}

/// Extracts the signed-in user. A guest gets 401, so put this behind [`crate::guard::authenticated`],
/// which redirects browsers to the login page first.
pub struct CurrentUser(pub Auth);

impl<S: Sync> FromRequestParts<S> for CurrentUser {
    type Rejection = WebError;

    async fn from_request_parts(parts: &mut Parts, _: &S) -> Result<Self, Self::Rejection> {
        parts
            .extensions
            .get::<Auth>()
            .cloned()
            .map(Self)
            .ok_or(WebError::Unauthorized)
    }
}

/// Fingerprints a password hash, so a session ends when the password changes without the session
/// holding the hash.
pub fn password_fingerprint(hash: &str) -> String {
    hmac_hex(&config::app_key(), hash)
}

pub fn verify_password(password: &str, hash: &str) -> bool {
    bcrypt::verify(password, hash).unwrap_or(false)
}

/// Hashes with the cost Laravel defaulted to. `$2b$` verifies the same as Laravel's `$2y$`.
pub fn hash_password(password: &str) -> Result<String> {
    Ok(bcrypt::hash(password, 12)?)
}

/// Marks the session signed in. The caller regenerates the session ID.
pub fn login(session: &Session, user: &User) {
    clear_two_factor(session);
    session.forget(TWO_FACTOR_VIA_DEVICE);
    session.put(LOGIN_KEY, user.id);
    session.put(PASSWORD_KEY, password_fingerprint(&user.password));
}

pub fn logout(session: &Session) {
    clear_two_factor(session);
    session.forget(TWO_FACTOR_VIA_DEVICE);
    session.forget(LOGIN_KEY);
    session.forget(PASSWORD_KEY);
    session.forget(TOKEN_KEY);
}

/// Laravel's `logoutOtherDevices` and session rotation: every other session for the account fails
/// its token check on its next request, and other browsers' remember cookies stop working. This
/// session holds the new token. Issue this browser's remember cookie afterwards.
pub async fn rotate_session_token(ctx: &Ctx, session: &Session, user_id: i64) -> Result<()> {
    let token = random_string(60);
    sqlx::query("UPDATE users SET session_token = ?, remember_token = NULL, updated_at = NOW() WHERE id = ?")
        .bind(&token)
        .bind(user_id)
        .execute(&ctx.db)
        .await?;
    session.put(TOKEN_KEY, token);
    Ok(())
}

/// Records a sign-in. The address is only stored when `STORE_USER_IP` is on.
pub async fn record_login(ctx: &Ctx, user_id: i64, ip: &str) -> Result<()> {
    let host = if config_store_ip() { ip } else { "" };
    sqlx::query("UPDATE users SET lastlogin = NOW(), host = ?, updated_at = NOW() WHERE id = ?")
        .bind(host)
        .bind(user_id)
        .execute(&ctx.db)
        .await?;
    Ok(())
}

fn config_store_ip() -> bool {
    matches!(
        nntmux_core::var_or("STORE_USER_IP", "false").as_str(),
        "1" | "true" | "on" | "yes"
    )
}

fn sign_remember(id: i64, token: &str, password: &str) -> String {
    let fingerprint = password_fingerprint(password);
    let signature = hmac_hex(
        &config::app_key(),
        &format!("remember|{id}|{token}|{fingerprint}"),
    );
    format!("{id}|{token}|{signature}")
}

/// Issues a new `remember_token`, and returns the cookie to send.
pub async fn issue_remember_cookie(ctx: &Ctx, user: &User) -> Result<String> {
    let token = random_string(60);
    sqlx::query("UPDATE users SET remember_token = ? WHERE id = ?")
        .bind(&token)
        .bind(user.id)
        .execute(&ctx.db)
        .await?;
    let value = config::encode_component(&sign_remember(user.id, &token, &user.password));
    Ok(config::cookie_header(
        REMEMBER_COOKIE,
        &value,
        REMEMBER_SECONDS,
    ))
}

/// Ends every remembered browser for the account, and returns a cookie that clears this one.
pub async fn revoke_remember(ctx: &Ctx, user_id: i64) -> Result<String> {
    sqlx::query("UPDATE users SET remember_token = ? WHERE id = ?")
        .bind(random_string(60))
        .bind(user_id)
        .execute(&ctx.db)
        .await?;
    Ok(config::cookie_header(REMEMBER_COOKIE, "", 0))
}

async fn user_from_remember(ctx: &Ctx, headers: &HeaderMap) -> Result<Option<User>> {
    let Some(cookie) = config::cookie_value(headers, REMEMBER_COOKIE) else {
        return Ok(None);
    };
    let cookie = config::decode_component(&cookie);
    let mut pieces = cookie.splitn(3, '|');
    let (Some(id), Some(_), Some(_)) = (pieces.next(), pieces.next(), pieces.next()) else {
        return Ok(None);
    };
    let Ok(id) = id.parse::<i64>() else {
        return Ok(None);
    };
    let Some(user) = User::find(&ctx.db, id).await? else {
        return Ok(None);
    };
    let Some(token) = user
        .remember_token
        .as_deref()
        .filter(|token| !token.is_empty())
    else {
        return Ok(None);
    };
    let matches = constant_time_eq(&cookie, &sign_remember(id, token, &user.password));
    Ok(matches.then_some(user))
}

async fn load_session_user(ctx: &Ctx, session: &Session) -> Result<Option<User>> {
    let Some(id) = session.get_i64(LOGIN_KEY) else {
        return Ok(None);
    };
    User::find(&ctx.db, id).await
}

/// Resolves the user for the request. Runs inside the session layer.
pub async fn layer(mut request: Request, next: Next) -> Response {
    let (Some(ctx), Some(session)) = (
        request.extensions().get::<Ctx>().cloned(),
        request.extensions().get::<Session>().cloned(),
    ) else {
        return next.run(request).await;
    };

    let mut user = match load_session_user(&ctx, &session).await {
        Ok(user) => user,
        Err(failure) => return WebError::from(failure).into_response(),
    };
    let mut clear_remember = false;
    if user.is_none() && config::cookie_value(request.headers(), REMEMBER_COOKIE).is_some() {
        match user_from_remember(&ctx, request.headers()).await {
            Ok(Some(found)) if found.eligible_for_authentication() => {
                login(&session, &found);
                session.migrate();
                user = Some(found);
            }
            Ok(_) => clear_remember = true,
            Err(failure) => return WebError::from(failure).into_response(),
        }
    }

    if let Some(found) = &user {
        // Laravel's AuthenticateSession: a changed password ends older sessions.
        let fingerprint = password_fingerprint(&found.password);
        match session.get_str(PASSWORD_KEY) {
            Some(stored) if !constant_time_eq(&stored, &fingerprint) => {
                return end_session(&session, guard::expects_json(request.headers()));
            }
            Some(_) => {}
            None => session.put(PASSWORD_KEY, fingerprint),
        }
        // A session holding a token that isn't the account's lost to a newer sign-in.
        let current = found.session_token.as_deref().unwrap_or_default();
        if !current.is_empty() {
            match session.get_str(TOKEN_KEY) {
                Some(stored) if !constant_time_eq(&stored, current) => {
                    return end_session(
                        &session,
                        request.headers().contains_key("x-requested-with"),
                    );
                }
                Some(_) => {}
                None => session.put(TOKEN_KEY, current),
            }
        }
    }

    let auth = match user {
        Some(found) => match Access::load(&ctx.db, found.id).await {
            Ok(access) => Some(Auth {
                user: Arc::new(found),
                access: Arc::new(access),
            }),
            Err(failure) => return WebError::from(failure).into_response(),
        },
        None => None,
    };
    if let Some(auth) = &auth {
        request.extensions_mut().insert(auth.clone());
    }

    let mut response = next.run(request).await;
    if let Some(auth) = auth {
        // The header layer reads this to mark the response uncacheable.
        response.extensions_mut().insert(auth);
    }
    if clear_remember {
        let clear = config::cookie_header(REMEMBER_COOKIE, "", 0);
        if let Ok(value) = clear.parse() {
            response.headers_mut().append(SET_COOKIE, value);
        }
    }
    response
}

fn end_session(session: &Session, ajax: bool) -> Response {
    logout(session);
    session.invalidate();
    session.regenerate_token();
    if ajax {
        return WebError::Unauthorized.into_response();
    }
    session.flash(
        "info",
        "You were logged out because your account was signed in on another device or session.",
    );
    Redirect::to("/login").into_response()
}

/// True when the request passes two-factor: the account has none, the feature is off, or this
/// session or a trusted device already proved it.
pub async fn two_factor_satisfied(
    ctx: &Ctx,
    session: &Session,
    user: &User,
    headers: &HeaderMap,
) -> Result<bool> {
    let enabled: Option<(i8, Option<String>)> = sqlx::query_as(
        "SELECT google2fa_enable, google2fa_secret FROM password_securities WHERE user_id = ? LIMIT 1",
    )
    .bind(user.id)
    .fetch_optional(&ctx.db)
    .await?;
    let required = matches!(&enabled, Some((1, Some(secret))) if !secret.is_empty());

    let trusted = match config::cookie_value(headers, TRUSTED_DEVICE_COOKIE) {
        Some(cookie) => {
            trusted_device_valid(ctx, &config::decode_component(&cookie), user.id).await?
        }
        None => false,
    };
    if trusted {
        mark_two_factor_passed(session);
        session.put(TWO_FACTOR_VIA_DEVICE, true);
        return Ok(true);
    }
    // The device that vouched for this session no longer does.
    if session.pull(TWO_FACTOR_VIA_DEVICE).is_some() {
        clear_two_factor(session);
    }
    if !required || !config::otp_enabled() {
        return Ok(true);
    }
    Ok(two_factor_still_valid(session))
}

fn two_factor_still_valid(session: &Session) -> bool {
    if !session.get_bool(TWO_FACTOR_PASSED) {
        return false;
    }
    let lifetime = config::otp_lifetime_minutes();
    if lifetime != 0 {
        let at = session.get_i64(TWO_FACTOR_TIME).unwrap_or(0);
        if (Utc::now().timestamp() - at) / 60 > lifetime {
            clear_two_factor(session);
            return false;
        }
    }
    if config::otp_keep_alive() {
        session.put(TWO_FACTOR_TIME, Utc::now().timestamp());
    }
    true
}

pub fn mark_two_factor_passed(session: &Session) {
    session.put(TWO_FACTOR_PASSED, true);
    session.put(TWO_FACTOR_TIME, Utc::now().timestamp());
}

pub fn clear_two_factor(session: &Session) {
    session.forget(TWO_FACTOR_PASSED);
    session.forget(TWO_FACTOR_TIME);
}

/// What the trusted-device cookie holds. The token only means something when its hash is in
/// `trusted_devices`, so the cookie needs no signature.
#[derive(Deserialize, Serialize)]
pub struct TrustedDeviceCookie {
    pub user_id: i64,
    pub token: String,
    pub expires_at: i64,
}

fn trusted_device_hash(token: &str) -> String {
    hmac_hex(&config::app_key(), token)
}

pub async fn trusted_device_valid(ctx: &Ctx, cookie: &str, user_id: i64) -> Result<bool> {
    let Ok(data) = serde_json::from_str::<TrustedDeviceCookie>(cookie) else {
        return Ok(false);
    };
    if data.user_id != user_id || Utc::now().timestamp() > data.expires_at || data.token.is_empty()
    {
        return Ok(false);
    }
    let updated = sqlx::query(
        "UPDATE trusted_devices SET last_used_at = NOW(), updated_at = NOW()
         WHERE user_id = ? AND token_hash = ? AND expires_at > NOW()",
    )
    .bind(user_id)
    .bind(trusted_device_hash(&data.token))
    .execute(&ctx.db)
    .await?;
    if updated.rows_affected() > 0 {
        return Ok(true);
    }
    // MariaDB reports zero rows when `last_used_at` didn't change within the same second.
    let found: Option<i64> = sqlx::query_scalar(
        "SELECT CAST(id AS SIGNED) FROM trusted_devices WHERE user_id = ? AND token_hash = ? AND expires_at > NOW()",
    )
    .bind(user_id)
    .bind(trusted_device_hash(&data.token))
    .fetch_optional(&ctx.db)
    .await?;
    Ok(found.is_some())
}

/// Trusts this browser for 30 days, and returns the cookie to send.
pub async fn issue_trusted_device(
    ctx: &Ctx,
    user_id: i64,
    ip: Option<&str>,
    user_agent: Option<&str>,
) -> Result<String> {
    let token = random_string(64);
    let expires = Utc::now() + chrono::Duration::days(TRUSTED_DEVICE_DAYS);
    let agent = user_agent.map(|agent| agent.chars().take(500).collect::<String>());
    sqlx::query(
        "INSERT INTO trusted_devices (user_id, token_hash, expires_at, ip_address, user_agent, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, NOW(), NOW())",
    )
    .bind(user_id)
    .bind(trusted_device_hash(&token))
    .bind(expires)
    .bind(ip)
    .bind(agent)
    .execute(&ctx.db)
    .await?;
    let value = serde_json::to_string(&TrustedDeviceCookie {
        user_id,
        token,
        expires_at: expires.timestamp(),
    })?;
    Ok(config::cookie_header(
        TRUSTED_DEVICE_COOKIE,
        &config::encode_component(&value),
        TRUSTED_DEVICE_DAYS as u64 * 24 * 3600,
    ))
}

/// The resolved address, stored per `STORE_USER_IP`, for code that only has request parts.
pub fn request_ip(parts: &Parts) -> String {
    client_ip::from_parts(parts).to_string()
}

/// Logs a failure to the channel Laravel called `failed_login`, for fail2ban-style tooling.
pub fn log_failed_login(username: &str, ip: &str) {
    warn!(target: "failed_login", username, ip, "failed login attempt");
}

/// Writes a best-effort value, logging rather than failing the request.
pub fn log_if_failed<T>(result: Result<T>, what: &str) {
    if let Err(failure) = result {
        error!(
            error = format!("{failure:#}"),
            what, "best-effort step failed"
        );
    }
}
