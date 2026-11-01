//! A cookie-keeping browser and fixtures for the site's integration tests.
#![allow(dead_code)]

use axum::Router;
use axum::body::{Body, to_bytes};
use axum::extract::Extension;
use axum::http::{Request, StatusCode, header};
use axum::response::Response;
use nntmux_runtime::Ctx;
use nntmux_site::router;
use nntmux_testkit::TestDb;
use nntmux_webkit::auth;
use nntmux_webkit::mail::outbox_key;
use redis::AsyncCommands;
use tower::ServiceExt;

pub const PASSWORD: &str = "Vq7!correct-horse-battery-xyzzy";

pub struct Browser {
    pub app: Router,
    pub cookies: Vec<(String, String)>,
    /// Each browser is its own address, so login lockouts don't cross tests.
    pub ip: String,
}

impl Browser {
    pub fn new(ctx: &Ctx) -> Self {
        let app = router()
            .layer(Extension(ctx.clone()))
            .with_state(ctx.clone());
        let unique: u32 = rand::random();
        let ip = format!("198.51.{}.{}", unique % 250, (unique >> 8) % 250);
        Self {
            app,
            cookies: Vec::new(),
            ip,
        }
    }

    pub async fn send(
        &mut self,
        request: axum::http::request::Builder,
        body: String,
    ) -> (Response, String) {
        let cookies = self
            .cookies
            .iter()
            .map(|(k, v)| format!("{k}={v}"))
            .collect::<Vec<_>>()
            .join("; ");
        let request = request
            .header(header::COOKIE, cookies)
            .header("x-forwarded-for", self.ip.clone())
            .body(Body::from(body))
            .unwrap();
        let response = self.app.clone().oneshot(request).await.unwrap();
        for value in response.headers().get_all(header::SET_COOKIE) {
            let (pair, attributes) = value.to_str().unwrap().split_once(';').unwrap();
            let (name, value) = pair.split_once('=').unwrap();
            self.cookies.retain(|(existing, _)| existing != name);
            if !attributes.contains("Max-Age=0") {
                self.cookies.push((name.to_owned(), value.to_owned()));
            }
        }
        let (parts, body) = response.into_parts();
        let bytes = to_bytes(body, 4 * 1024 * 1024).await.unwrap();
        (
            Response::from_parts(parts, Body::empty()),
            String::from_utf8_lossy(&bytes).into_owned(),
        )
    }

    pub async fn get(&mut self, path: &str) -> (Response, String) {
        self.send(Request::get(path), String::new()).await
    }

    /// Posts a form with the CSRF token a real page would hold.
    pub async fn post(&mut self, path: &str, fields: &[(&str, &str)]) -> (Response, String) {
        let (_, html) = self.get("/login").await;
        let token = extract_token(&html);
        let mut body = format!("_token={token}");
        for (key, value) in fields {
            let encoded: String = nntmux_webkit::config::encode_component(value);
            body.push_str(&format!("&{key}={encoded}"));
        }
        let request =
            Request::post(path).header(header::CONTENT_TYPE, "application/x-www-form-urlencoded");
        self.send(request, body).await
    }

    pub fn has_cookie(&self, name: &str) -> bool {
        self.cookies.iter().any(|(existing, _)| existing == name)
    }
}

pub fn extract_token(html: &str) -> String {
    let marker = "name=\"csrf-token\" content=\"";
    let start = html.find(marker).expect("the page has a CSRF meta tag") + marker.len();
    html[start..start + 40].to_owned()
}

pub fn location(response: &Response) -> String {
    response.headers()[header::LOCATION]
        .to_str()
        .unwrap()
        .to_owned()
}

pub async fn fixture() -> Option<(TestDb, Ctx)> {
    let db = TestDb::create().await.unwrap()?;
    let redis_url = std::env::var("TEST_REDIS_URL").ok()?;
    let mut config = nntmux_core::Config::from_env().unwrap();
    config.redis_url = redis_url.clone();
    let client = redis::Client::open(redis_url).unwrap();
    let redis = redis::aio::ConnectionManager::new(client).await.unwrap();
    let ctx = Ctx {
        config: std::sync::Arc::new(config),
        db: db.pool.clone(),
        redis,
    };
    Some((db, ctx))
}

pub async fn add_user(db: &sqlx::MySqlPool, name: &str, verified: bool, roles_id: i32) -> i64 {
    sqlx::query(
        "INSERT INTO users (username, email, password, roles_id, api_token, verified, email_verified_at, created_at)
         VALUES (?, ?, ?, ?, ?, ?, IF(?, NOW(), NULL), NOW())",
    )
    .bind(name)
    .bind(format!("{name}@example.com"))
    .bind(auth::hash_password(PASSWORD).unwrap())
    .bind(roles_id)
    .bind(format!("token-{name}"))
    .bind(verified)
    .bind(verified)
    .execute(db)
    .await
    .unwrap()
    .last_insert_id() as i64
}

/// A unique login, so parallel tests' Redis keys and mail can't collide.
pub fn unique(prefix: &str) -> String {
    format!("{prefix}{:08x}", rand::random::<u32>())
}

pub async fn take_mail(ctx: &Ctx, to: &str) -> Vec<serde_json::Value> {
    let mut redis = ctx.redis.clone();
    let all: Vec<String> = redis.lrange(outbox_key(), 0, -1).await.unwrap();
    let mut mine = Vec::new();
    for raw in all {
        let parsed: serde_json::Value = serde_json::from_str(&raw).unwrap();
        if parsed["to"] == to {
            let _: i64 = redis.lrem(outbox_key(), 1, &raw).await.unwrap();
            mine.push(parsed);
        }
    }
    mine
}

pub fn link_in(mail: &serde_json::Value, marker: &str) -> String {
    let text = mail["text"].as_str().unwrap();
    let start = text.find(marker).expect("the mail has the link");
    let tail = &text[start..];
    tail.split_whitespace().next().unwrap().to_owned()
}

impl Browser {
    /// Logging out needs a token from a page a signed-in user can open.
    pub async fn post_with_token(&mut self, path: &str) -> (Response, String) {
        let (_, html) = self.get("/confirm-password").await;
        let token = extract_token(&html);
        let request =
            Request::post(path).header(header::CONTENT_TYPE, "application/x-www-form-urlencoded");
        self.send(request, format!("_token={token}")).await
    }
}

pub async fn browser_is_signed_in(browser: &mut Browser) -> bool {
    let (response, _) = browser.get("/login").await;
    response.status() != StatusCode::OK
}

impl Browser {
    /// Posts a form as a signed-in user, whose token comes from a page they can open.
    pub async fn post_authed(&mut self, path: &str, fields: &[(&str, &str)]) -> (Response, String) {
        let (_, html) = self.get("/confirm-password").await;
        let token = extract_token(&html);
        let mut body = format!("_token={token}");
        for (key, value) in fields {
            let encoded: String = nntmux_webkit::config::encode_component(value);
            body.push_str(&format!("&{key}={encoded}"));
        }
        let request = Request::post(path)
            .header(header::CONTENT_TYPE, "application/x-www-form-urlencoded")
            .header(header::ACCEPT, "application/json");
        self.send(request, body).await
    }
}
