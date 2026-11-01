//! CSRF checks and `_method` form overrides.

use axum::body::{Body, to_bytes};
use axum::extract::Request;
use axum::http::header::CONTENT_TYPE;
use axum::http::{Method, StatusCode};
use axum::middleware::Next;
use axum::response::{IntoResponse, Response};

use crate::config::constant_time_eq;
use crate::error::WebError;
use crate::input::{Input, parse_pairs};
use crate::session::Session;

/// Multipart bodies carry cover and logo uploads.
const MAX_BODY: usize = 32 * 1024 * 1024;

/// Paths called by machines, which authenticate some other way.
const EXEMPT: [&str; 2] = ["/failed", "/btcpay/webhook"];

/// A text field from a multipart body. The fields a form sends before its files are all this
/// layer needs, so this doesn't parse file parts.
fn multipart_field(body: &[u8], name: &str) -> Option<String> {
    let needle = format!("name=\"{name}\"");
    let start = find(body, needle.as_bytes())?;
    let rest = &body[start + needle.len()..];
    let value_start = find(rest, b"\r\n\r\n")? + 4;
    let value = &rest[value_start..];
    let end = find(value, b"\r\n")?;
    String::from_utf8(value[..end].to_vec()).ok()
}

fn find(haystack: &[u8], needle: &[u8]) -> Option<usize> {
    haystack
        .windows(needle.len())
        .position(|window| window == needle)
}

/// Applies a form's `_method` before routing, because axum picks the method inside the router.
/// `nntmux-web` wraps the whole app in this. API and feed paths pass straight through.
pub async fn override_method(request: Request) -> Result<Request, StatusCode> {
    let path = request.uri().path();
    if request.method() != Method::POST || path.starts_with("/api") || path.starts_with("/rss") {
        return Ok(request);
    }
    let content_type = request
        .headers()
        .get(CONTENT_TYPE)
        .and_then(|value| value.to_str().ok())
        .unwrap_or_default()
        .to_ascii_lowercase();
    let form = content_type.starts_with("application/x-www-form-urlencoded");
    if !form && !content_type.starts_with("multipart/form-data") {
        return Ok(request);
    }
    let (mut parts, body) = request.into_parts();
    let bytes = to_bytes(body, MAX_BODY)
        .await
        .map_err(|_| StatusCode::PAYLOAD_TOO_LARGE)?;
    let method = if form {
        parse_pairs(&bytes)
            .into_iter()
            .find(|(name, _)| name == "_method")
            .map(|(_, value)| value)
    } else {
        multipart_field(&bytes, "_method")
    };
    match method
        .unwrap_or_default()
        .trim()
        .to_ascii_uppercase()
        .as_str()
    {
        "PUT" => parts.method = Method::PUT,
        "PATCH" => parts.method = Method::PATCH,
        "DELETE" => parts.method = Method::DELETE,
        _ => {}
    }
    Ok(Request::from_parts(parts, Body::from(bytes)))
}

pub async fn layer(request: Request, next: Next) -> Response {
    let (mut parts, body) = request.into_parts();
    let mut input = Input::from_query(parts.uri.query().unwrap_or_default());
    if matches!(parts.method, Method::GET | Method::HEAD | Method::OPTIONS) {
        parts.extensions.insert(input);
        return next.run(Request::from_parts(parts, body)).await;
    }

    let content_type = parts
        .headers
        .get(CONTENT_TYPE)
        .and_then(|value| value.to_str().ok())
        .unwrap_or_default()
        .to_ascii_lowercase();
    let Ok(bytes) = to_bytes(body, MAX_BODY).await else {
        return StatusCode::PAYLOAD_TOO_LARGE.into_response();
    };
    if content_type.starts_with("application/x-www-form-urlencoded") {
        input = input.with_form(&bytes);
    } else if content_type.contains("json") {
        input = input.with_json(&bytes);
    } else if content_type.starts_with("multipart/form-data") {
        if let Some(value) = multipart_field(&bytes, "_token") {
            input = input.with_field("_token", value);
        }
    }

    if !EXEMPT.contains(&parts.uri.path()) {
        let expected = parts
            .extensions
            .get::<Session>()
            .map(Session::token)
            .unwrap_or_default();
        let sent = input.get("_token").or_else(|| {
            parts
                .headers
                .get("x-csrf-token")
                .and_then(|value| value.to_str().ok())
                .map(str::to_owned)
        });
        let valid =
            !expected.is_empty() && sent.is_some_and(|sent| constant_time_eq(&sent, &expected));
        if !valid {
            return WebError::PageExpired.into_response();
        }
    }

    parts.extensions.insert(input);
    next.run(Request::from_parts(parts, Body::from(bytes)))
        .await
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn multipart_fields_are_found_before_files() {
        let body = b"--x\r\nContent-Disposition: form-data; name=\"_token\"\r\n\r\nabc\r\n--x\r\nContent-Disposition: form-data; name=\"file\"; filename=\"a\"\r\n\r\n\xff\xfe\r\n--x--\r\n";
        assert_eq!(multipart_field(body, "_token").as_deref(), Some("abc"));
        assert_eq!(multipart_field(body, "_method"), None);
    }
}
