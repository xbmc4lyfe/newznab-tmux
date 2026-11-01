//! Route guards, the `auth`, `isVerified`, `2fa` and `role` middleware. Add them with
//! `route_layer(from_fn(...))`. The last one added runs first, so add `authenticated` last.

use axum::Json;
use axum::extract::Request;
use axum::http::{HeaderMap, Method, StatusCode, header};
use axum::middleware::Next;
use axum::response::{IntoResponse, Redirect, Response};
use nntmux_runtime::Ctx;
use serde_json::json;

use crate::auth::{self, Auth};
use crate::config;
use crate::error::WebError;
use crate::session::Session;

/// Laravel's `expectsJson`: an XHR that doesn't take HTML, or anything asking for JSON.
pub fn expects_json(headers: &HeaderMap) -> bool {
    let accept = headers
        .get(header::ACCEPT)
        .and_then(|value| value.to_str().ok())
        .unwrap_or_default();
    let ajax = headers.contains_key("x-requested-with");
    accept.contains("json") || accept.contains("+json") || (ajax && !accept.contains("text/html"))
}

/// A same-origin path and query for `url.intended`.
fn full_path(request: &Request) -> String {
    request
        .uri()
        .path_and_query()
        .map_or_else(|| "/".to_owned(), |path| path.as_str().to_owned())
}

/// The address to return to after signing in, cleared once read.
pub fn pull_intended(session: &Session, fallback: &str) -> String {
    session
        .pull("url.intended")
        .and_then(|value| value.as_str().map(str::to_owned))
        .filter(|path| path.starts_with('/') && !path.starts_with("//") && !path.contains('\\'))
        .unwrap_or_else(|| fallback.to_owned())
}

/// Redirects guests to `/login`, keeping where they were headed on a GET.
pub async fn authenticated(request: Request, next: Next) -> Response {
    if request.extensions().get::<Auth>().is_some() {
        return next.run(request).await;
    }
    if expects_json(request.headers()) {
        return (StatusCode::UNAUTHORIZED, Json(json!({"message": "Unauthenticated."}))).into_response();
    }
    if request.method() == Method::GET
        && let Some(session) = request.extensions().get::<Session>()
    {
        session.put("url.intended", full_path(&request));
    }
    Redirect::to("/login").into_response()
}

/// Sends signed-in visitors away from the login and registration forms.
pub async fn guest(request: Request, next: Next) -> Response {
    if request.extensions().get::<Auth>().is_some() {
        return Redirect::to("/").into_response();
    }
    next.run(request).await
}

/// Laravel's `isVerified`.
pub async fn verified(request: Request, next: Next) -> Response {
    let unverified = request
        .extensions()
        .get::<Auth>()
        .is_some_and(|auth| !auth.user.has_verified_email());
    if !unverified {
        return next.run(request).await;
    }
    if expects_json(request.headers()) {
        return WebError::Forbidden(Some("Your email address is not verified.".to_owned())).json();
    }
    Redirect::to("/email/verify").into_response()
}

/// Asks for the one-time code when the account needs one and this browser hasn't given it.
pub async fn two_factor(request: Request, next: Next) -> Response {
    let (Some(ctx), Some(session), Some(auth)) = (
        request.extensions().get::<Ctx>().cloned(),
        request.extensions().get::<Session>().cloned(),
        request.extensions().get::<Auth>().cloned(),
    ) else {
        return next.run(request).await;
    };
    match auth::two_factor_satisfied(&ctx, &session, &auth.user, request.headers()).await {
        Ok(true) => next.run(request).await,
        Ok(false) => {
            let path = request.uri().path();
            if path.starts_with("/passkeys") || path == "/confirm-password" {
                session.put("url.intended", "/profileedit#security");
            } else if request.method() == Method::GET {
                session.put("url.intended", full_path(&request));
            }
            if expects_json(request.headers()) {
                return (
                    StatusCode::FORBIDDEN,
                    Json(json!({"message": "Two-factor authentication required.", "redirect": "/2fa/verify"})),
                )
                    .into_response();
            }
            Redirect::to("/2fa/verify").into_response()
        }
        Err(failure) => WebError::from(failure).into_response(),
    }
}

/// Requires one of the roles. `from_fn(|r, n| guard::roles(r, n, &["Admin"]))`.
pub async fn roles(request: Request, next: Next, allowed: &'static [&'static str]) -> Response {
    let permitted = request
        .extensions()
        .get::<Auth>()
        .is_some_and(|auth| auth.access.has_any_role(allowed));
    if permitted {
        next.run(request).await
    } else {
        WebError::Forbidden(Some("User does not have the right roles.".to_owned())).into_response()
    }
}

/// Requires one of the roles or the permission: Laravel's `role_or_permission`.
pub async fn role_or_permission(
    request: Request,
    next: Next,
    allowed: &'static [&'static str],
    permission: &'static str,
) -> Response {
    let permitted = request.extensions().get::<Auth>().is_some_and(|auth| {
        auth.access.has_any_role(allowed) || auth.access.can(permission)
    });
    if permitted {
        next.run(request).await
    } else {
        WebError::Forbidden(Some("User does not have the right permissions.".to_owned())).into_response()
    }
}

/// Keeps `url()`-style absolute links on the configured origin.
pub fn absolute(path: &str) -> String {
    format!("{}/{}", config::app_url(), path.trim_start_matches('/'))
}
