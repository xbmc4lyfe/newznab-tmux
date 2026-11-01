//! Passkey sign-in and management.

use anyhow::Result;
use axum::Json;
use axum::Router;
use axum::extract::{Path, Request, State};
use axum::http::header::SET_COOKIE;
use axum::http::{HeaderMap, StatusCode};
use axum::middleware::{Next, from_fn};
use axum::response::{IntoResponse, Redirect, Response};
use axum::routing::{delete, get, post};
use chrono::Utc;
use nntmux_core::var_or;
use nntmux_runtime::Ctx;
use nntmux_webkit::user::User;
use nntmux_webkit::{
    ClientIp, CurrentUser, Input, Session, WebResult, auth, config, guard, throttle,
};
use serde_json::{Value, json};

use super::confirm::CONFIRMED_AT;
use super::{back, captcha_error};
use crate::guest::Captcha;
use crate::webauthn;

const SIGN_IN_CHALLENGE: &str = "passkey-authentication-options";
const REGISTER_CHALLENGE: &str = "passkey-registration-options";
const MESSAGE: &str = "authenticatePasskey::message";
const REASON: &str = "authenticatePasskey::reason";
const INVALID: &str = "Could not login using the given passkey";
const REGISTER_FAILED: &str = "Something went wrong generating the passkey.";
/// `password.confirm:…,900`
const CONFIRM_SECONDS: i64 = 900;
/// The largest browser response worth parsing as JSON.
const MAX_RESPONSE: usize = 64 * 1024;

pub fn router() -> Router<Ctx> {
    let sign_in = Router::new()
        .route(
            "/passkeys/authentication-options",
            get(authentication_options),
        )
        .route("/passkeys/authenticate", post(authenticate))
        .route_layer(from_fn(|request, next| {
            throttle::limit(request, next, "passkeys", 6, 60)
        }));
    let manage = Router::new()
        .route("/passkeys/register-options", post(register_options))
        .route("/passkeys", post(store))
        .route("/passkeys/{passkey}", delete(destroy))
        .route_layer(from_fn(password_confirmed))
        .route_layer(from_fn(|request, next| {
            throttle::limit(request, next, "passkeys-manage", 20, 60)
        }))
        .route_layer(from_fn(guard::two_factor))
        .route_layer(from_fn(guard::verified))
        .route_layer(from_fn(guard::authenticated));
    sign_in.merge(manage)
}

/// Laravel's `password.confirm`: JSON callers get 423, which the page turns into the form.
async fn password_confirmed(session: Session, request: Request, next: Next) -> Response {
    let confirmed = session.get_i64(CONFIRMED_AT).unwrap_or(0);
    if Utc::now().timestamp() - confirmed <= CONFIRM_SECONDS {
        return next.run(request).await;
    }
    if guard::expects_json(request.headers()) {
        return (
            StatusCode::LOCKED,
            Json(json!({"message": "Password confirmation required."})),
        )
            .into_response();
    }
    Redirect::to("/confirm-password").into_response()
}

fn valid_rp_id(id: &str) -> bool {
    !id.is_empty()
        && id.parse::<std::net::IpAddr>().is_err()
        && (id == "localhost" || id.contains('.'))
}

/// The configured id, then the host of `APP_URL`. PHP also trusted `X-Forwarded-Host` here;
/// the relying party shouldn't depend on a request header.
fn rp_id() -> String {
    let configured = var_or("PASSKEY_RELYING_PARTY_ID", "");
    if valid_rp_id(&configured) {
        return configured;
    }
    let url = config::app_url();
    let authority = url.split_once("://").map_or(url.as_str(), |(_, rest)| rest);
    let host = authority.split(['/', ':']).next().unwrap_or_default();
    if valid_rp_id(host) {
        host.to_owned()
    } else {
        "localhost".to_owned()
    }
}

/// `scheme://host[:port]` of `APP_URL`.
fn app_origin() -> String {
    let url = config::app_url();
    let (scheme, rest) = url.split_once("://").unwrap_or(("", url.as_str()));
    format!("{scheme}://{}", rest.split('/').next().unwrap_or_default())
}

fn party<'a>(rp_id: &'a str, app_origin: &'a str) -> webauthn::Party<'a> {
    webauthn::Party { rp_id, app_origin }
}

/// One of the values the WebAuthn spec allows, or `preferred`.
fn requirement(key: &str) -> String {
    let value = var_or(key, "preferred");
    if matches!(value.as_str(), "required" | "preferred" | "discouraged") {
        value
    } else {
        "preferred".to_owned()
    }
}

fn challenge() -> String {
    webauthn::encode(&rand::random::<[u8; 32]>())
}

async fn authentication_options(session: Session) -> Json<Value> {
    let challenge = challenge();
    session.put(SIGN_IN_CHALLENGE, challenge.clone());
    Json(json!({"challenge": challenge, "rpId": rp_id(), "allowCredentials": []}))
}

fn refused(session: &Session, message: &str, reason: &str) -> Response {
    session.flash(MESSAGE, message);
    session.flash(REASON, reason);
    Redirect::to("/login").into_response()
}

/// Finds and checks the passkey, then saves its new counter. `None` is a failed sign-in.
async fn check_passkey(ctx: &Ctx, raw: &str, challenge: &str) -> Result<Option<i64>> {
    if raw.len() > MAX_RESPONSE {
        return Ok(None);
    }
    let Ok(credential) = serde_json::from_str::<Value>(raw) else {
        return Ok(None);
    };
    let (rp_id, app_origin) = (rp_id(), app_origin());
    let Ok(id) = webauthn::credential_id(&credential) else {
        return Ok(None);
    };
    // PHP's `credential_id` column mangled binary ids, so the stored JSON is what identifies one.
    let row: Option<(i64, i64, String)> = sqlx::query_as(
        "SELECT CAST(id AS SIGNED), CAST(authenticatable_id AS SIGNED), CAST(data AS CHAR) FROM passkeys
         WHERE JSON_VALUE(data, '$.publicKeyCredentialId') = ? ORDER BY id LIMIT 1",
    )
    .bind(&id)
    .fetch_optional(&ctx.db)
    .await?;
    let Some((passkey, user, data)) = row else {
        return Ok(None);
    };
    let stored: Value = serde_json::from_str(&data)?;
    let updated = match webauthn::verify_assertion(
        &credential,
        &stored,
        challenge,
        &party(&rp_id, &app_origin),
    ) {
        Ok(updated) => updated,
        Err(error) => {
            tracing::warn!(passkey, %error, "passkey sign-in refused");
            return Ok(None);
        }
    };
    sqlx::query(
        "UPDATE passkeys SET data = ?, last_used_at = NOW(), updated_at = NOW() WHERE id = ?",
    )
    .bind(updated.to_string())
    .bind(passkey)
    .execute(&ctx.db)
    .await?;
    Ok(Some(user))
}

async fn authenticate(
    State(ctx): State<Ctx>,
    session: Session,
    input: Input,
    ClientIp(ip): ClientIp,
) -> WebResult {
    let ip = ip.to_string();
    // A captcha widget can expire during the prompt, so only a token that was sent is checked.
    if Captcha::from_env().is_some_and(|captcha| input.filled(captcha.field()))
        && let Some((_, message)) = captcha_error(&input, &ip, "").await
    {
        auth::log_failed_login("passkey", &ip);
        return Ok(refused(&session, &message, "captcha"));
    }
    let Some(challenge) = session
        .pull(SIGN_IN_CHALLENGE)
        .and_then(|value| value.as_str().map(str::to_owned))
    else {
        return Ok(refused(
            &session,
            "Your passkey sign-in session expired or was interrupted. Please try signing in with a passkey again.",
            "missing_auth_options",
        ));
    };
    let Some(user_id) = check_passkey(
        &ctx,
        &input.str("start_authentication_response"),
        &challenge,
    )
    .await?
    else {
        auth::log_failed_login("passkey", &ip);
        return Ok(refused(&session, INVALID, "invalid_passkey"));
    };
    let user = User::find(&ctx.db, user_id)
        .await?
        .filter(User::eligible_for_authentication);
    let Some(user) = user else {
        auth::log_failed_login("passkey", &ip);
        return Ok(refused(&session, INVALID, "ineligible_account"));
    };
    session.regenerate();
    auth::log_if_failed(
        auth::record_login(&ctx, user.id, &ip).await,
        "recording the login",
    );
    // Anything confirmed for an account signed in on this session doesn't carry over.
    session.forget(CONFIRMED_AT);
    session.forget(REGISTER_CHALLENGE);
    session.forget("2fa:trusted_device");
    auth::login(&session, &user);
    // A passkey is enough on its own, so no code is asked for. `login` clears this, so it follows.
    auth::mark_two_factor_passed(&session);
    auth::rotate_session_token(&ctx, &session, user.id).await?;
    session.flash("info", "You have been logged in");
    let mut response = Redirect::to(&guard::pull_intended(&session, "/")).into_response();
    if input.boolean("remember") {
        let cookie = auth::issue_remember_cookie(&ctx, &user).await?;
        response.headers_mut().append(SET_COOKIE, cookie.parse()?);
    }
    Ok(response)
}

fn name_error(name: &str) -> Option<Response> {
    let message = if name.is_empty() {
        "The name field is required."
    } else if name.chars().count() > 255 {
        "The name field must not be greater than 255 characters."
    } else {
        return None;
    };
    Some(
        (
            StatusCode::UNPROCESSABLE_ENTITY,
            Json(json!({"message": message, "errors": {"name": [message]}})),
        )
            .into_response(),
    )
}

async fn register_options(
    session: Session,
    input: Input,
    CurrentUser(signed_in): CurrentUser,
) -> WebResult {
    let name = input.str("name");
    if let Some(response) = name_error(&name) {
        return Ok(response);
    }
    let user = &signed_in.user;
    let challenge = challenge();
    session.put(REGISTER_CHALLENGE, challenge.clone());
    let display = Some(user.full_name())
        .filter(|full| !full.trim().is_empty())
        .unwrap_or_else(|| user.username.clone());
    let account = if user.email.is_empty() {
        user.username.clone()
    } else {
        user.email.clone()
    };
    let mut selection = json!({
            "residentKey": requirement("PASSKEY_RESIDENT_KEY"),
    "requireResidentKey": requirement("PASSKEY_RESIDENT_KEY") == "required",
    "userVerification": requirement("PASSKEY_USER_VERIFICATION"),
    });
    let attachment = var_or("PASSKEY_AUTHENTICATOR_ATTACHMENT", "");
    if matches!(attachment.as_str(), "platform" | "cross-platform") {
        selection["authenticatorAttachment"] = json!(attachment);
    }
    Ok(Json(json!({
        "ok": true,
        "options": {
            "rp": {"name": var_or("APP_NAME", "NNTmux"), "id": rp_id()},
            "user": {"name": account, "id": webauthn::encode(user.id.to_string().as_bytes()), "displayName": display},
            "challenge": challenge,
            // ES256, RS256 for Windows Hello, then EdDSA.
            "pubKeyCredParams": [
                {"type": "public-key", "alg": -7},
                {"type": "public-key", "alg": -257},
                {"type": "public-key", "alg": -8},
            ],
            "authenticatorSelection": selection,
            "attestation": "none",
            "hints": ["client-device", "hybrid"],
            "extensions": {"credProps": true},
        },
    }))
    .into_response())
}

async fn register(
    ctx: &Ctx,
    user_id: i64,
    name: &str,
    raw: &str,
    challenge: &str,
) -> Result<Value> {
    anyhow::ensure!(raw.len() <= MAX_RESPONSE, "the response is too large");
    let credential: Value = serde_json::from_str(raw)?;
    let (rp_id, app_origin) = (rp_id(), app_origin());
    let require_verified = requirement("PASSKEY_USER_VERIFICATION") == "required";
    let (id, data) = webauthn::verify_registration(
        &credential,
        challenge,
        &party(&rp_id, &app_origin),
        require_verified,
        user_id,
    )?;
    let taken: i64 = sqlx::query_scalar(
        "SELECT COUNT(*) FROM passkeys WHERE JSON_VALUE(data, '$.publicKeyCredentialId') = ?",
    )
    .bind(&id)
    .fetch_one(&ctx.db)
    .await?;
    anyhow::ensure!(taken == 0, "the credential is already registered");
    let inserted = sqlx::query(
        "INSERT INTO passkeys (authenticatable_id, name, credential_id, data, created_at, updated_at) VALUES (?, ?, ?, ?, NOW(), NOW())",
    )
    .bind(user_id)
    .bind(name)
    .bind(&id)
    .bind(data.to_string())
    .execute(&ctx.db)
    .await?;
    Ok(json!({
        "id": inserted.last_insert_id(),
        "name": name,
        "last_used_at": null,
        "created_at": Utc::now().to_rfc3339(),
    }))
}

async fn store(
    State(ctx): State<Ctx>,
    session: Session,
    input: Input,
    CurrentUser(signed_in): CurrentUser,
) -> WebResult {
    let name = input.str("name");
    if let Some(response) = name_error(&name) {
        return Ok(response);
    }
    let challenge = session
        .pull(REGISTER_CHALLENGE)
        .and_then(|value| value.as_str().map(str::to_owned))
        .unwrap_or_default();
    match register(
        &ctx,
        signed_in.user.id,
        &name,
        &input.str("passkey"),
        &challenge,
    )
    .await
    {
        Ok(passkey) => Ok(Json(json!({"ok": true, "passkey": passkey})).into_response()),
        Err(error) => {
            tracing::error!(user = signed_in.user.id, %error, "passkey registration failed");
            Ok((
                StatusCode::UNPROCESSABLE_ENTITY,
                Json(json!({"ok": false, "message": REGISTER_FAILED})),
            )
                .into_response())
        }
    }
}

async fn destroy(
    State(ctx): State<Ctx>,
    session: Session,
    headers: HeaderMap,
    Path(passkey): Path<String>,
    CurrentUser(signed_in): CurrentUser,
) -> WebResult {
    let Ok(passkey) = passkey.parse::<i64>() else {
        return Ok(StatusCode::NOT_FOUND.into_response());
    };
    let deleted = sqlx::query("DELETE FROM passkeys WHERE id = ? AND authenticatable_id = ?")
        .bind(passkey)
        .bind(signed_in.user.id)
        .execute(&ctx.db)
        .await?
        .rows_affected()
        > 0;
    if guard::expects_json(&headers) {
        return Ok(Json(json!({"ok": deleted})).into_response());
    }
    if deleted {
        session.flash("success", "Passkey deleted.");
    } else {
        session.flash("error", "Passkey not found.");
    }
    Ok(back(&headers, "/profileedit"))
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn relying_party_ids_are_hosts() {
        assert!(valid_rp_id("example.com"));
        assert!(valid_rp_id("localhost"));
        assert!(!valid_rp_id(""));
        assert!(!valid_rp_id("127.0.0.1"));
        assert!(!valid_rp_id("intranet"));
    }
}
