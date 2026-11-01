//! Server-side sessions in Redis, with Laravel's flash semantics: a flashed value is readable on
//! the next request and then gone. Everyone signs in again once at cutover, so nothing here
//! reads Laravel's session format.

use std::sync::{Arc, Mutex, MutexGuard};

use axum::extract::{FromRequestParts, Request};
use axum::http::header::SET_COOKIE;
use axum::http::request::Parts;
use axum::http::{HeaderValue, StatusCode};
use axum::middleware::Next;
use axum::response::Response;
use nntmux_runtime::Ctx;
use rand::Rng;
use rand::distr::Alphanumeric;
use redis::AsyncCommands;
use serde::{Deserialize, Serialize};
use serde_json::{Map, Value};
use tracing::warn;

use crate::config;

/// Random letters and digits, the shape of Laravel's `Str::random`.
pub fn random_string(length: usize) -> String {
    rand::rng()
        .sample_iter(Alphanumeric)
        .take(length)
        .map(char::from)
        .collect()
}

#[derive(Default, Deserialize, Serialize)]
struct Stored {
    data: Map<String, Value>,
    flash: Map<String, Value>,
}

struct Inner {
    id: String,
    stored: Stored,
    /// What the last request flashed. Readable now, gone after this request.
    previous: Map<String, Value>,
    /// IDs to delete on save, after a regenerate or invalidate.
    retired: Vec<String>,
}

/// One browser's session. Cheap to clone: every clone is the same session, and the middleware
/// saves it after the handler returns.
#[derive(Clone)]
pub struct Session(Arc<Mutex<Inner>>);

impl Session {
    pub fn new() -> Self {
        Self::from_parts(random_string(40), Stored::default())
    }

    fn from_parts(id: String, stored: Stored) -> Self {
        let mut inner = Inner {
            id,
            stored,
            previous: Map::new(),
            retired: Vec::new(),
        };
        inner.previous = std::mem::take(&mut inner.stored.flash);
        Self(Arc::new(Mutex::new(inner)))
    }

    fn lock(&self) -> MutexGuard<'_, Inner> {
        self.0.lock().unwrap_or_else(|poison| poison.into_inner())
    }

    pub fn id(&self) -> String {
        self.lock().id.clone()
    }

    pub fn get(&self, key: &str) -> Option<Value> {
        self.lock().stored.data.get(key).cloned()
    }

    pub fn get_str(&self, key: &str) -> Option<String> {
        self.get(key)
            .and_then(|value| value.as_str().map(str::to_owned))
    }

    pub fn get_bool(&self, key: &str) -> bool {
        self.get(key).and_then(|value| value.as_bool()) == Some(true)
    }

    pub fn get_i64(&self, key: &str) -> Option<i64> {
        self.get(key).and_then(|value| value.as_i64())
    }

    pub fn put(&self, key: &str, value: impl Into<Value>) {
        self.lock().stored.data.insert(key.to_owned(), value.into());
    }

    pub fn forget(&self, key: &str) {
        self.lock().stored.data.remove(key);
    }

    pub fn pull(&self, key: &str) -> Option<Value> {
        self.lock().stored.data.remove(key)
    }

    /// Stores a value for the next request only.
    pub fn flash(&self, key: &str, value: impl Into<Value>) {
        self.lock()
            .stored
            .flash
            .insert(key.to_owned(), value.into());
    }

    /// A value flashed by the last request, or earlier in this one.
    pub fn flashed(&self, key: &str) -> Option<Value> {
        let inner = self.lock();
        inner
            .stored
            .flash
            .get(key)
            .or_else(|| inner.previous.get(key))
            .cloned()
    }

    pub fn flashed_str(&self, key: &str) -> Option<String> {
        self.flashed(key)
            .and_then(|value| value.as_str().map(str::to_owned))
    }

    /// Flashes validation errors by field, for the form's next render.
    pub fn flash_errors(&self, errors: &[(&str, String)]) {
        let mut map = Map::new();
        for (field, message) in errors {
            map.entry((*field).to_owned())
                .or_insert_with(|| Value::Array(Vec::new()))
                .as_array_mut()
                .expect("just inserted as an array")
                .push(Value::String(message.clone()));
        }
        self.flash("errors", Value::Object(map));
    }

    /// The first flashed error for a field.
    pub fn error(&self, field: &str) -> Option<String> {
        self.flashed("errors")?
            .get(field)?
            .get(0)?
            .as_str()
            .map(str::to_owned)
    }

    /// Every flashed error, in field order.
    pub fn errors(&self) -> Vec<String> {
        let Some(Value::Object(errors)) = self.flashed("errors") else {
            return Vec::new();
        };
        errors
            .values()
            .filter_map(Value::as_array)
            .flatten()
            .filter_map(Value::as_str)
            .map(str::to_owned)
            .collect()
    }

    /// Flashes submitted fields so a redirected form can refill them. Never pass a password.
    pub fn flash_input(&self, fields: &[(&str, String)]) {
        let map: Map<String, Value> = fields
            .iter()
            .map(|(key, value)| ((*key).to_owned(), Value::String(value.clone())))
            .collect();
        self.flash("_old_input", Value::Object(map));
    }

    /// A field from the last submission, or an empty string.
    pub fn old(&self, field: &str) -> String {
        self.flashed("_old_input")
            .and_then(|old| old.get(field).and_then(Value::as_str).map(str::to_owned))
            .unwrap_or_default()
    }

    /// The CSRF token, created on first use.
    pub fn token(&self) -> String {
        let mut inner = self.lock();
        if let Some(Value::String(token)) = inner.stored.data.get("_token") {
            return token.clone();
        }
        let token = random_string(40);
        inner
            .stored
            .data
            .insert("_token".to_owned(), Value::String(token.clone()));
        token
    }

    pub fn regenerate_token(&self) {
        self.lock().stored.data.remove("_token");
    }

    /// A new ID and CSRF token for the same data. Call on every privilege change, to stop session
    /// fixation: a planted cookie's ID and token both stop working.
    pub fn regenerate(&self) {
        self.migrate();
        self.regenerate_token();
    }

    /// A new ID that keeps the CSRF token, for a sign-in the visitor didn't submit a form for.
    /// A remember cookie can sign in mid-POST, and that POST's token must stay good.
    pub fn migrate(&self) {
        let mut inner = self.lock();
        let old = std::mem::replace(&mut inner.id, random_string(40));
        inner.retired.push(old);
    }

    /// Drops all data and takes a new ID.
    pub fn invalidate(&self) {
        self.regenerate();
        let mut inner = self.lock();
        inner.stored = Stored::default();
        inner.previous = Map::new();
    }

    fn snapshot(&self) -> (String, Vec<String>, Option<String>) {
        let mut inner = self.lock();
        let retired = std::mem::take(&mut inner.retired);
        let body = serde_json::to_string(&inner.stored).ok();
        (inner.id.clone(), retired, body)
    }
}

impl Default for Session {
    fn default() -> Self {
        Self::new()
    }
}

impl<S: Sync> FromRequestParts<S> for Session {
    type Rejection = StatusCode;

    async fn from_request_parts(parts: &mut Parts, _: &S) -> Result<Self, Self::Rejection> {
        parts
            .extensions
            .get::<Session>()
            .cloned()
            .ok_or(StatusCode::INTERNAL_SERVER_ERROR)
    }
}

fn redis_key(id: &str) -> String {
    format!("{}web_session:{id}", key_prefix())
}

/// `NNTMUX_WEB_KEY_PREFIX` separates parallel test runs and deployments sharing one Redis.
pub(crate) fn key_prefix() -> String {
    nntmux_core::var_or("NNTMUX_WEB_KEY_PREFIX", "nntmux:")
}

fn valid_id(id: &str) -> bool {
    id.len() == 40 && id.bytes().all(|byte| byte.is_ascii_alphanumeric())
}

async fn load(ctx: &Ctx, id: &str) -> Option<Stored> {
    let mut redis = ctx.redis.clone();
    match redis.get::<_, Option<String>>(redis_key(id)).await {
        Ok(Some(body)) => serde_json::from_str(&body).ok(),
        Ok(None) => None,
        Err(failure) => {
            warn!(error = %failure, "reading the session failed, continuing as a new one");
            None
        }
    }
}

async fn save(ctx: &Ctx, session: &Session) {
    let (id, retired, body) = session.snapshot();
    let mut redis = ctx.redis.clone();
    for old in retired {
        if let Err(failure) = redis.del::<_, ()>(redis_key(&old)).await {
            warn!(error = %failure, "deleting a retired session failed");
        }
    }
    let Some(body) = body else { return };
    let ttl = config::session_lifetime_seconds();
    if let Err(failure) = redis.set_ex::<_, _, ()>(redis_key(&id), body, ttl).await {
        warn!(error = %failure, "saving the session failed");
    }
}

/// Loads the session, runs the request, then saves it and refreshes the cookie.
pub async fn layer(mut request: Request, next: Next) -> Response {
    let ctx = request.extensions().get::<Ctx>().cloned();
    let cookie = config::session_cookie();
    let existing = config::cookie_value(request.headers(), &cookie).filter(|id| valid_id(id));
    let session = match (&ctx, existing) {
        (Some(ctx), Some(id)) => match load(ctx, &id).await {
            Some(stored) => Session::from_parts(id, stored),
            None => Session::new(),
        },
        _ => Session::new(),
    };
    request.extensions_mut().insert(session.clone());
    let mut response = next.run(request).await;
    if let Some(ctx) = &ctx {
        save(ctx, &session).await;
    }
    let header = config::cookie_header(&cookie, &session.id(), config::session_lifetime_seconds());
    if let Ok(value) = HeaderValue::from_str(&header) {
        response.headers_mut().append(SET_COOKIE, value);
    }
    response
}

#[cfg(test)]
mod tests {
    use super::*;

    fn next_request(session: &Session) -> Session {
        let (id, _, body) = session.snapshot();
        Session::from_parts(id, serde_json::from_str(&body.unwrap()).unwrap())
    }

    #[test]
    fn a_flash_lives_for_exactly_one_following_request() {
        let first = Session::new();
        first.flash("success", "saved");
        assert_eq!(first.flashed_str("success").as_deref(), Some("saved"));
        let second = next_request(&first);
        assert_eq!(second.flashed_str("success").as_deref(), Some("saved"));
        let third = next_request(&second);
        assert_eq!(third.flashed_str("success"), None);
    }

    #[test]
    fn regenerate_keeps_data_and_retires_the_old_id() {
        let session = Session::new();
        session.put("login_web", 7);
        let before = session.id();
        session.regenerate();
        assert_ne!(session.id(), before);
        assert_eq!(session.get_i64("login_web"), Some(7));
        let (_, retired, _) = session.snapshot();
        assert_eq!(retired, vec![before]);
    }

    #[test]
    fn regenerate_rotates_the_token_and_migrate_keeps_it() {
        let session = Session::new();
        let token = session.token();
        session.migrate();
        assert_eq!(session.token(), token);
        session.regenerate();
        assert_ne!(session.token(), token);
    }

    #[test]
    fn invalidate_drops_data() {
        let session = Session::new();
        session.put("login_web", 7);
        session.flash("info", "bye");
        session.invalidate();
        assert_eq!(session.get_i64("login_web"), None);
        assert_eq!(session.flashed_str("info"), None);
    }

    #[test]
    fn errors_and_old_input_round_trip() {
        let first = Session::new();
        first.flash_errors(&[
            ("email", "Taken".to_owned()),
            ("email", "Bad".to_owned()),
            ("name", "Short".to_owned()),
        ]);
        first.flash_input(&[("email", "a@b.c".to_owned())]);
        let second = next_request(&first);
        assert_eq!(second.error("email").as_deref(), Some("Taken"));
        assert_eq!(second.errors(), vec!["Taken", "Bad", "Short"]);
        assert_eq!(second.old("email"), "a@b.c");
        assert_eq!(second.old("missing"), "");
    }

    #[test]
    fn the_token_is_stable_until_regenerated() {
        let session = Session::new();
        let token = session.token();
        assert_eq!(token.len(), 40);
        assert_eq!(session.token(), token);
        session.regenerate_token();
        assert_ne!(session.token(), token);
    }
}
