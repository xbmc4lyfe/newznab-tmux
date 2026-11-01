//! Security headers on every browser response, and no caching of anything session-bound.

use axum::extract::{FromRequestParts, Request};
use axum::http::header::{CACHE_CONTROL, HeaderName, VARY};
use axum::http::request::Parts;
use axum::http::{HeaderValue, StatusCode};
use axum::middleware::Next;
use axum::response::Response;
use base64::Engine;
use base64::engine::general_purpose::STANDARD;
use rand::Rng;

use crate::auth::Auth;
use crate::config;

/// The nonce for this page's inline scripts.
#[derive(Clone)]
pub struct CspNonce(pub String);

impl<S: Sync> FromRequestParts<S> for CspNonce {
    type Rejection = StatusCode;

    async fn from_request_parts(parts: &mut Parts, _: &S) -> Result<Self, Self::Rejection> {
        parts
            .extensions
            .get::<CspNonce>()
            .cloned()
            .ok_or(StatusCode::INTERNAL_SERVER_ERROR)
    }
}

fn csp(nonce: &str) -> String {
    let grafana = nntmux_core::optional("GRAFANA_URL")
        .filter(|url| url.starts_with("http"))
        .and_then(|url| {
            let scheme_end = url.find("://")? + 3;
            let end = url[scheme_end..]
                .find('/')
                .map_or(url.len(), |slash| scheme_end + slash);
            Some(format!(" {}", &url[..end]))
        })
        .unwrap_or_default();
    // Alpine's CSP build carries an evaluator it never calls, and it can't be tree-shaken, so
    // 'unsafe-eval' stays. Inline scripts need the nonce.
    let hosts = "https://challenges.cloudflare.com https://cdn.tiny.cloud https://cdn.jsdelivr.net/ https://static.cloudflareinsights.com/ https://cdnjs.cloudflare.com/ https://unpkg.com/ https://www.google.com https://www.gstatic.com";
    let styles = "'self' 'unsafe-inline' https://fonts.googleapis.com https://fonts.bunny.net https://cdn.jsdelivr.net/ https://cdnjs.cloudflare.com/ https://cdn.tiny.cloud";
    [
        "default-src 'self'".to_owned(),
        format!("script-src 'self' 'nonce-{nonce}' 'unsafe-eval' 'strict-dynamic' {hosts} blob:"),
        format!("script-src-elem 'self' 'nonce-{nonce}' {hosts}"),
        "script-src-attr 'none'".to_owned(),
        format!("style-src {styles}"),
        format!("style-src-elem {styles}"),
        "font-src 'self' https://fonts.gstatic.com https://fonts.bunny.net https://cdnjs.cloudflare.com/ https://cdn.tiny.cloud data:".to_owned(),
        "img-src 'self' data: https: blob:".to_owned(),
        "connect-src 'self' https://www.google.com https://cdn.tiny.cloud https://sp.tinymce.com".to_owned(),
        format!("frame-src 'self' https://www.google.com https://www.gstatic.com https://challenges.cloudflare.com https://cdn.tiny.cloud data: blob:{grafana}"),
        format!("child-src 'self' https://www.google.com https://challenges.cloudflare.com https://cdn.tiny.cloud blob:{grafana}"),
        "worker-src 'self' blob:".to_owned(),
        "object-src 'none'".to_owned(),
        "base-uri 'self'".to_owned(),
        "form-action 'self'".to_owned(),
        "frame-ancestors 'self'".to_owned(),
    ]
    .join("; ")
}

/// Sign-in pages stay out of caches even for guests, so a cached form can't carry a stale token
/// or a flash from another visitor.
const AUTH_PATHS: [&str; 7] = [
    "/login",
    "/register",
    "/logout",
    "/forgottenpassword",
    "/password/reset",
    "/2fa/verify",
    "/2faVerify",
];

fn set(response: &mut Response, name: &'static str, value: &str) {
    if let Ok(value) = HeaderValue::from_str(value) {
        response
            .headers_mut()
            .insert(HeaderName::from_static(name), value);
    }
}

pub async fn layer(mut request: Request, next: Next) -> Response {
    let bytes: [u8; 16] = rand::rng().random();
    let nonce = STANDARD.encode(bytes);
    request.extensions_mut().insert(CspNonce(nonce.clone()));
    let auth_path = AUTH_PATHS.contains(&request.uri().path());
    let bearer = request.headers().contains_key("authorization");

    let mut response = next.run(request).await;

    if !config::turnstile_enabled() {
        set(&mut response, "content-security-policy", &csp(&nonce));
    }
    set(&mut response, "x-frame-options", "SAMEORIGIN");
    set(&mut response, "x-content-type-options", "nosniff");
    set(&mut response, "referrer-policy", "strict-origin-when-cross-origin");

    let signed_in = response.extensions().get::<Auth>().is_some();
    if signed_in || auth_path {
        response.headers_mut().insert(
            CACHE_CONTROL,
            HeaderValue::from_static("no-cache, no-store, must-revalidate, private, max-age=0, s-maxage=0"),
        );
        set(&mut response, "pragma", "no-cache");
        set(&mut response, "expires", "Thu, 01 Jan 1970 00:00:00 GMT");
        set(&mut response, "cdn-cache-control", "no-store");
        set(&mut response, "cloudflare-cdn-cache-control", "no-store");
        let vary = if bearer { "Cookie, Authorization" } else { "Cookie" };
        response.headers_mut().append(VARY, HeaderValue::from_static(vary));
    }
    response
}
