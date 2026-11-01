//! Signing in and out, registration, password reset, email verification and the 2FA challenge.

mod confirm;
mod login;
mod passkeys;
mod password;
mod register;
mod two_factor;
mod verify;

use axum::Router;
use axum::http::HeaderMap;
use axum::http::header::REFERER;
use axum::middleware::from_fn;
use axum::response::{IntoResponse, Redirect, Response};
use axum::routing::{get, post};
use nntmux_runtime::Ctx;
use nntmux_webkit::{Input, Session, guard, throttle};

use crate::guest::Captcha;

pub(crate) use passkeys::router as passkeys_router;
pub(crate) use register::registration_status;
pub(crate) use two_factor::verify_code;
pub(crate) use verify::send_verification;

pub fn router() -> Router<Ctx> {
    let guest_forms = Router::new()
        .route("/login", get(login::show).post(login::submit))
        .route("/register", get(register::show).post(register::submit))
        .route_layer(from_fn(guard::guest));
    let guest_mail = Router::new()
        .route("/forgottenpassword", post(password::request_link))
        .route("/password/reset", post(password::request_link))
        .route("/password/update", post(password::reset))
        .route("/resetpassword", get(password::legacy_form))
        .route_layer(from_fn(|request, next| {
            throttle::limit(request, next, "password", 6, 60)
        }))
        .route_layer(from_fn(guard::guest));
    let guest_pages = Router::new()
        .route("/forgottenpassword", get(password::show_request))
        .route("/password/reset", get(password::show_request))
        .route("/password/reset/{token}", get(password::show_reset))
        .route_layer(from_fn(guard::guest));
    let challenge = Router::new()
        .route(
            "/2fa/verify",
            get(two_factor::show).post(two_factor::submit),
        )
        .route("/2faVerify", post(two_factor::submit))
        .route_layer(from_fn(|request, next| {
            throttle::limit(request, next, "2fa", 6, 60)
        }));
    let verification = Router::new()
        .route("/email/verification-notification", post(verify::resend))
        .route_layer(from_fn(|request, next| {
            throttle::limit(request, next, "verification", 6, 60)
        }))
        .route_layer(from_fn(guard::authenticated));
    let verify_link = Router::new()
        .route("/email/verify/{id}/{hash}", get(verify::confirm))
        .route_layer(from_fn(|request, next| {
            throttle::limit(request, next, "verification-link", 6, 60)
        }));
    let notice = Router::new()
        .route("/email/verify", get(verify::show))
        .route_layer(from_fn(guard::authenticated));
    let confirm_submit = Router::new()
        .route("/confirm-password", post(confirm::submit))
        .route_layer(from_fn(|request, next| {
            throttle::limit(request, next, "confirm-password", 6, 60)
        }));
    let confirm_routes = Router::new()
        .route("/confirm-password", get(confirm::show))
        .merge(confirm_submit)
        .route_layer(from_fn(guard::two_factor))
        .route_layer(from_fn(guard::verified))
        .route_layer(from_fn(guard::authenticated));
    Router::new()
        .merge(guest_mail)
        .merge(guest_pages)
        .merge(guest_forms)
        .merge(challenge)
        .merge(verification)
        .merge(verify_link)
        .merge(notice)
        .merge(confirm_routes)
        .route("/logout", post(login::logout))
}

/// `redirect()->back()`: the same-origin referrer, or the fallback.
pub(crate) fn back(headers: &HeaderMap, fallback: &str) -> Response {
    let origin = nntmux_webkit::config::app_url();
    let target = headers
        .get(REFERER)
        .and_then(|value| value.to_str().ok())
        .and_then(|referer| referer.strip_prefix(origin.as_str()))
        .filter(|path| path.starts_with('/') && !path.starts_with("//"))
        .unwrap_or(fallback);
    Redirect::to(target).into_response()
}

/// Checks the captcha when one is on. The error to flash under its field, if it failed.
pub(crate) async fn captcha_error(
    input: &Input,
    ip: &str,
    message: &str,
) -> Option<(&'static str, String)> {
    let captcha = Captcha::from_env()?;
    let field = captcha.field();
    match input.get(field) {
        None => Some((field, message.to_owned())),
        Some(token) if captcha.verify(&token, ip).await => None,
        Some(_) => Some((
            field,
            "The human verification failed. Please try again.".to_owned(),
        )),
    }
}

/// Where a reset or verification form returned errors keeps what was typed, never the password.
pub(crate) fn flash_form(session: &Session, errors: &[(&str, String)], input: &[(&str, String)]) {
    session.flash_errors(errors);
    session.flash_input(input);
}
