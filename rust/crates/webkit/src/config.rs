//! Web settings from the environment, under the names Laravel read.

use hmac::{Hmac, Mac};
use nntmux_core::{optional, parsed_or, var_or};
use sha2::Sha256;

pub fn app_name() -> String {
    var_or("APP_NAME", "NNTmux")
}

/// The site's public origin, with no trailing slash.
pub fn app_url() -> String {
    var_or("APP_URL", "http://localhost")
        .trim_end_matches('/')
        .to_owned()
}

/// `APP_KEY` signs cookies and links and hashes trusted-device tokens, so rows Laravel wrote
/// stay valid. `nntmux-web` refuses to start without it, through [`require_app_key`], so the
/// fixed value only ever signs in tests.
pub fn app_key() -> String {
    optional("APP_KEY").unwrap_or_else(|| "nntmux-test-key".to_owned())
}

pub fn require_app_key() -> anyhow::Result<()> {
    anyhow::ensure!(optional("APP_KEY").is_some(), "APP_KEY must be set");
    Ok(())
}

pub fn session_cookie() -> String {
    var_or("SESSION_COOKIE", "nntmux_session")
}

pub fn session_lifetime_seconds() -> u64 {
    parsed_or("SESSION_LIFETIME", 120u64).unwrap_or(120) * 60
}

/// `Secure` follows `SESSION_SECURE_COOKIE`, then whether the site is served over https.
pub fn secure_cookies() -> bool {
    match optional("SESSION_SECURE_COOKIE") {
        Some(value) => matches!(value.as_str(), "1" | "true" | "on" | "yes"),
        None => app_url().starts_with("https://"),
    }
}

pub fn cookie_domain() -> Option<String> {
    optional("SESSION_DOMAIN").filter(|domain| domain != "null")
}

pub fn otp_enabled() -> bool {
    matches!(
        var_or("OTP_ENABLED", "false").as_str(),
        "1" | "true" | "on" | "yes"
    )
}

pub fn otp_keep_alive() -> bool {
    matches!(
        var_or("OTP_KEEP_ALIVE", "true").as_str(),
        "1" | "true" | "on" | "yes"
    )
}

pub fn otp_lifetime_minutes() -> i64 {
    parsed_or("OTP_LIFETIME", 0i64).unwrap_or(0)
}

/// Turnstile needs the strict CSP off, because its sandboxed iframes conflict with it.
pub fn turnstile_enabled() -> bool {
    var_or("CAPTCHA_PROVIDER", "") == "turnstile"
        && matches!(
            var_or("TURNSTILE_ENABLED", "false").as_str(),
            "1" | "true" | "on" | "yes"
        )
}

pub fn hmac_hex(key: &str, message: &str) -> String {
    let mut mac = Hmac::<Sha256>::new_from_slice(key.as_bytes()).expect("HMAC takes any key");
    mac.update(message.as_bytes());
    hex::encode(mac.finalize().into_bytes())
}

/// Compares in time that doesn't depend on where the values differ.
pub fn constant_time_eq(a: &str, b: &str) -> bool {
    a.len() == b.len()
        && a.bytes()
            .zip(b.bytes())
            .fold(0u8, |diff, (x, y)| diff | (x ^ y))
            == 0
}

/// A cookie header value with the session's attributes. `max_age` of 0 removes the cookie.
pub fn cookie_header(name: &str, value: &str, max_age: u64) -> String {
    let mut cookie = format!("{name}={value}; Path=/; Max-Age={max_age}; HttpOnly; SameSite=Lax");
    if let Some(domain) = cookie_domain() {
        cookie.push_str("; Domain=");
        cookie.push_str(&domain);
    }
    if secure_cookies() {
        cookie.push_str("; Secure");
    }
    cookie
}

/// The value of one cookie from a request's `Cookie` headers.
pub fn cookie_value(headers: &axum::http::HeaderMap, name: &str) -> Option<String> {
    headers
        .get_all(axum::http::header::COOKIE)
        .iter()
        .filter_map(|value| value.to_str().ok())
        .flat_map(|value| value.split(';'))
        .filter_map(|pair| pair.trim().split_once('='))
        .find(|(key, _)| *key == name)
        .map(|(_, value)| value.to_owned())
}

/// Percent-encodes a value for a cookie or a URL query.
pub fn encode_component(value: &str) -> String {
    form_urlencoded::byte_serialize(value.as_bytes()).collect()
}

/// Reverses [`encode_component`].
pub fn decode_component(value: &str) -> String {
    form_urlencoded::parse(format!("v={value}").as_bytes())
        .next()
        .map(|(_, decoded)| decoded.into_owned())
        .unwrap_or_default()
}
