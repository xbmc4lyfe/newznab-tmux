//! Data for `guest.html`, the plain layout of the sign-in pages, and their captcha.

use axum::extract::FromRequestParts;
use axum::http::request::Parts;
use chrono::{Datelike, Utc};
use nntmux_core::{optional, var_or};
use nntmux_runtime::Ctx;
use nntmux_webkit::assets::{Assets, assets};
use nntmux_webkit::headers::CspNonce;
use nntmux_webkit::{Session, WebError, config};
use serde_json::json;
use tracing::warn;

/// `layout` in the guest templates. Field names match what `theme_init.html` reads.
#[derive(Clone)]
pub struct GuestLayout {
    pub app_name: String,
    pub csrf: String,
    pub nonce: String,
    pub assets: Assets,
    pub year: i32,
    pub signed_in: bool,
    pub theme: &'static str,
    pub scheme: &'static str,
    pub flash_json: String,
    pub errors: Vec<String>,
    pub captcha: Option<Captcha>,
    session: Session,
}

impl GuestLayout {
    pub fn error(&self, field: &str) -> Option<String> {
        self.session.error(field)
    }

    pub fn old(&self, field: &str) -> String {
        self.session.old(field)
    }

    pub fn flashed(&self, key: &str) -> Option<String> {
        self.session.flashed_str(key)
    }
}

impl FromRequestParts<Ctx> for GuestLayout {
    type Rejection = WebError;

    async fn from_request_parts(parts: &mut Parts, _: &Ctx) -> Result<Self, Self::Rejection> {
        let session =
            parts.extensions.get::<Session>().cloned().ok_or_else(|| {
                WebError::Internal(anyhow::anyhow!("the web stack isn't installed"))
            })?;
        let nonce = parts
            .extensions
            .get::<CspNonce>()
            .map(|nonce| nonce.0.clone())
            .ok_or_else(|| WebError::Internal(anyhow::anyhow!("no CSP nonce")))?;
        let flash_json = json!({
            "success": session.flashed("success").or_else(|| session.flashed("status")),
            "error": session.flashed("error"),
            "warning": session.flashed("warning"),
            "info": session.flashed("info"),
        })
        .to_string();
        Ok(Self {
            app_name: config::app_name(),
            csrf: session.token(),
            nonce,
            assets: assets().clone(),
            year: Utc::now().year(),
            signed_in: false,
            theme: "light",
            scheme: "blue",
            flash_json,
            errors: session.errors(),
            captcha: Captcha::from_env(),
            session,
        })
    }
}

/// The configured captcha. Absent when off or not fully configured.
#[derive(Clone, Debug, PartialEq)]
pub struct Captcha {
    pub turnstile: bool,
    pub sitekey: String,
    secret: String,
}

fn on(key: &str) -> bool {
    matches!(var_or(key, "false").as_str(), "1" | "true" | "on" | "yes")
}

impl Captcha {
    pub fn from_env() -> Option<Self> {
        let turnstile = var_or("CAPTCHA_PROVIDER", "recaptcha") == "turnstile";
        let (flag, key, secret) = if turnstile {
            ("TURNSTILE_ENABLED", "TURNSTILE_SITEKEY", "TURNSTILE_SECRET")
        } else {
            ("NOCAPTCHA_ENABLED", "NOCAPTCHA_SITEKEY", "NOCAPTCHA_SECRET")
        };
        if !on(flag) {
            return None;
        }
        Some(Self {
            turnstile,
            sitekey: optional(key)?,
            secret: optional(secret)?,
        })
    }

    pub fn field(&self) -> &'static str {
        if self.turnstile {
            "cf-turnstile-response"
        } else {
            "g-recaptcha-response"
        }
    }

    pub fn script(&self) -> &'static str {
        if self.turnstile {
            "https://challenges.cloudflare.com/turnstile/v0/api.js"
        } else {
            "https://www.google.com/recaptcha/api.js"
        }
    }

    pub fn class(&self) -> &'static str {
        if self.turnstile {
            "cf-turnstile"
        } else {
            "g-recaptcha"
        }
    }

    /// Whether the provider accepts the token. Trouble reaching it counts as a failure.
    pub async fn verify(&self, token: &str, ip: &str) -> bool {
        let url = if self.turnstile {
            "https://challenges.cloudflare.com/turnstile/v0/siteverify"
        } else {
            "https://www.google.com/recaptcha/api/siteverify"
        };
        let body = form_urlencoded::Serializer::new(String::new())
            .append_pair("secret", &self.secret)
            .append_pair("response", token)
            .append_pair("remoteip", ip)
            .finish();
        let sent = reqwest::Client::new()
            .post(url)
            .timeout(std::time::Duration::from_secs(10))
            .header(
                reqwest::header::CONTENT_TYPE,
                "application/x-www-form-urlencoded",
            )
            .body(body)
            .send()
            .await;
        match sent {
            Ok(response) => match response.json::<serde_json::Value>().await {
                Ok(body) => body.get("success").and_then(serde_json::Value::as_bool) == Some(true),
                Err(failure) => {
                    warn!(error = %failure, "captcha response unreadable");
                    false
                }
            },
            Err(failure) => {
                warn!(error = %failure, "captcha verification request failed");
                false
            }
        }
    }
}
