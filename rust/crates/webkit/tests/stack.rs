//! The whole stack against real MariaDB and Redis: sessions across requests, CSRF, guards, the
//! one-session rule and the shared layout. Skips itself when the test services aren't set.

use askama::Template;
use axum::Router;
use axum::body::{Body, to_bytes};
use axum::extract::{Extension, State};
use axum::http::{Request, StatusCode, header};
use axum::middleware::{from_fn, map_request};
use axum::response::{Html, IntoResponse, Response};
use axum::routing::{delete, get, post};
use nntmux_runtime::Ctx;
use nntmux_testkit::TestDb;
use nntmux_webkit::{CurrentUser, Input, Layout, Session, WebResult, auth, csrf, guard, throttle};
use tower::{Layer, ServiceExt};

#[derive(Template)]
#[template(
    source = r#"{% extends "layout.html" %}{% block content %}<p id="page">{{ who }}</p>{% endblock %}"#,
    ext = "html"
)]
struct TestPage {
    layout: Layout,
    who: String,
}

async fn whoami(layout: Layout, CurrentUser(auth): CurrentUser) -> WebResult {
    let page = TestPage {
        layout: layout.titled("Who", "", ""),
        who: auth.user.username.clone(),
    };
    Ok(Html(page.render()?).into_response())
}

async fn guest_page(layout: Layout) -> WebResult {
    let page = TestPage {
        layout,
        who: "guest".to_owned(),
    };
    Ok(Html(page.render()?).into_response())
}

/// Signs in the user named by the `id` field, the way the login form will.
async fn sign_in(State(ctx): State<Ctx>, session: Session, input: Input) -> WebResult {
    let user = nntmux_webkit::User::find(&ctx.db, input.int("id", 0))
        .await?
        .ok_or(nntmux_webkit::WebError::NotFound)?;
    auth::login(&session, &user);
    session.regenerate();
    auth::rotate_session_token(&ctx, &session, user.id).await?;
    Ok(StatusCode::NO_CONTENT.into_response())
}

async fn flash(session: Session) -> Response {
    session.flash("success", "Saved it");
    StatusCode::NO_CONTENT.into_response()
}

async fn remove() -> &'static str {
    "deleted"
}

fn app(ctx: Ctx) -> Router {
    let private = Router::new()
        .route("/whoami", get(whoami))
        .route_layer(from_fn(guard::verified))
        .route_layer(from_fn(guard::authenticated));
    let limited = Router::new()
        .route("/limited", get(|| async { "ok" }))
        .route_layer(from_fn(|request, next| {
            throttle::limit(request, next, "test", 2, 60)
        }));
    let router = private
        .merge(limited)
        .route("/", get(guest_page))
        .route("/sign-in", post(sign_in))
        .route("/flash", post(flash))
        .route("/thing", delete(remove));
    let inner = nntmux_webkit::stack(router)
        .layer(Extension(ctx.clone()))
        .with_state(ctx);
    Router::new().fallback_service(map_request(csrf::override_method).layer(inner))
}

struct Browser {
    app: Router,
    cookies: Vec<(String, String)>,
}

impl Browser {
    fn cookie_header(&self) -> String {
        self.cookies
            .iter()
            .map(|(name, value)| format!("{name}={value}"))
            .collect::<Vec<_>>()
            .join("; ")
    }

    async fn send(
        &mut self,
        request: axum::http::request::Builder,
        body: &str,
    ) -> (Response, String) {
        let request = request
            .header(header::COOKIE, self.cookie_header())
            .body(Body::from(body.to_owned()))
            .unwrap();
        let response = self.app.clone().oneshot(request).await.unwrap();
        for value in response.headers().get_all(header::SET_COOKIE) {
            let (pair, _) = value.to_str().unwrap().split_once(';').unwrap();
            let (name, value) = pair.split_once('=').unwrap();
            self.cookies.retain(|(existing, _)| existing != name);
            self.cookies.push((name.to_owned(), value.to_owned()));
        }
        let (parts, body) = response.into_parts();
        let bytes = to_bytes(body, 4 * 1024 * 1024).await.unwrap();
        let text = String::from_utf8_lossy(&bytes).into_owned();
        (Response::from_parts(parts, Body::empty()), text)
    }

    async fn get(&mut self, path: &str) -> (Response, String) {
        self.send(Request::get(path), "").await
    }

    async fn post(&mut self, path: &str, body: &str) -> (Response, String) {
        let request =
            Request::post(path).header(header::CONTENT_TYPE, "application/x-www-form-urlencoded");
        self.send(request, body).await
    }

    /// The token a page put in its meta tag.
    async fn token(&mut self) -> String {
        let (_, html) = self.get("/").await;
        let marker = "name=\"csrf-token\" content=\"";
        let start = html.find(marker).expect("the layout has a CSRF meta tag") + marker.len();
        html[start..start + 40].to_owned()
    }
}

async fn fixture() -> Option<(TestDb, Ctx)> {
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

async fn add_user(db: &sqlx::MySqlPool, name: &str, verified: bool) -> i64 {
    sqlx::query(
        "INSERT INTO users (username, email, password, roles_id, api_token, verified, email_verified_at, created_at)
         VALUES (?, ?, ?, 1, ?, ?, IF(?, NOW(), NULL), NOW())",
    )
    .bind(name)
    .bind(format!("{name}@example.test"))
    .bind(auth::hash_password("Secret-1!").unwrap())
    .bind(format!("token-{name}"))
    .bind(verified)
    .bind(verified)
    .execute(db)
    .await
    .unwrap()
    .last_insert_id() as i64
}

#[tokio::test]
async fn sessions_csrf_and_guards_work_end_to_end() {
    let Some((db, ctx)) = fixture().await else {
        return;
    };
    let id = add_user(&db.pool, "alice", true).await;
    let unverified = add_user(&db.pool, "bobby", false).await;
    let mut browser = Browser {
        app: app(ctx.clone()),
        cookies: Vec::new(),
    };

    // Guests are sent to the login page, and it remembers where they were going.
    let (response, _) = browser.get("/whoami?x=1").await;
    assert_eq!(response.status(), StatusCode::SEE_OTHER);
    assert_eq!(response.headers()[header::LOCATION], "/login");

    // No token, no state change.
    let (response, _) = browser.post("/sign-in", &format!("id={id}")).await;
    assert_eq!(response.status().as_u16(), 419);

    let token = browser.token().await;
    let (response, _) = browser
        .post("/sign-in", &format!("id={id}&_token={token}"))
        .await;
    assert_eq!(response.status(), StatusCode::NO_CONTENT);

    // The session survives across requests, and the layout shows the account.
    let (response, html) = browser.get("/whoami").await;
    assert_eq!(response.status(), StatusCode::OK);
    assert!(html.contains(r#"<p id="page">alice</p>"#));
    assert!(html.contains("<title>Who - "));
    assert!(html.contains("My Download Basket"));
    assert!(
        response.headers()[header::CACHE_CONTROL]
            .to_str()
            .unwrap()
            .contains("no-store")
    );
    assert!(
        response
            .headers()
            .contains_key(header::CONTENT_SECURITY_POLICY)
    );
    let csp = response.headers()[header::CONTENT_SECURITY_POLICY]
        .to_str()
        .unwrap()
        .to_owned();
    assert!(html.contains(
        &format!("nonce=\"{}", csp.split("nonce-").nth(1).unwrap().split('\'').next().unwrap())
    ));

    // A newer sign-in elsewhere ends this session.
    let mut other = Browser {
        app: app(ctx.clone()),
        cookies: Vec::new(),
    };
    let other_token = other.token().await;
    other
        .post("/sign-in", &format!("id={id}&_token={other_token}"))
        .await;
    let (response, _) = browser.get("/whoami").await;
    assert_eq!(response.status(), StatusCode::SEE_OTHER);
    let (_, html) = browser.get("/").await;
    assert!(
        html.contains("signed in on another device"),
        "the reason is flashed"
    );

    // A changed password ends a session too.
    let (response, _) = other.get("/whoami").await;
    assert_eq!(response.status(), StatusCode::OK);
    sqlx::query("UPDATE users SET password = 'changed' WHERE id = ?")
        .bind(id)
        .execute(&db.pool)
        .await
        .unwrap();
    let (response, _) = other.get("/whoami").await;
    assert_eq!(response.status(), StatusCode::SEE_OTHER);

    // Unverified accounts go to the notice.
    let mut third = Browser {
        app: app(ctx.clone()),
        cookies: Vec::new(),
    };
    let token = third.token().await;
    third
        .post("/sign-in", &format!("id={unverified}&_token={token}"))
        .await;
    let (response, _) = third.get("/whoami").await;
    assert_eq!(response.headers()[header::LOCATION], "/email/verify");

    db.drop_database().await.unwrap();
}

#[tokio::test]
async fn flashes_show_once_and_method_overrides_route() {
    let Some((db, ctx)) = fixture().await else {
        return;
    };
    let mut browser = Browser {
        app: app(ctx),
        cookies: Vec::new(),
    };
    let token = browser.token().await;

    browser.post("/flash", &format!("_token={token}")).await;
    let (_, html) = browser.get("/").await;
    assert!(html.contains("Saved it"));
    let (_, html) = browser.get("/").await;
    assert!(!html.contains("Saved it"));

    let (response, body) = browser
        .post("/thing", &format!("_method=DELETE&_token={token}"))
        .await;
    assert_eq!(response.status(), StatusCode::OK);
    assert_eq!(body, "deleted");

    db.drop_database().await.unwrap();
}

#[tokio::test]
async fn throttling_counts_and_answers_429() {
    let Some((db, ctx)) = fixture().await else {
        return;
    };
    let mut browser = Browser {
        app: app(ctx.clone()),
        cookies: Vec::new(),
    };
    // Each run gets its own prefix through its own client address.
    let ip = format!("203.0.113.{}", rand_octet());
    let request = |ip: &str| Request::get("/limited").header("x-forwarded-for", ip.to_owned());
    assert_eq!(
        browser.send(request(&ip), "").await.0.status(),
        StatusCode::OK
    );
    assert_eq!(
        browser.send(request(&ip), "").await.0.status(),
        StatusCode::OK
    );
    let (response, _) = browser.send(request(&ip), "").await;
    assert_eq!(response.status(), StatusCode::TOO_MANY_REQUESTS);
    assert!(response.headers().contains_key(header::RETRY_AFTER));
    db.drop_database().await.unwrap();
}

fn rand_octet() -> u8 {
    std::time::SystemTime::now()
        .duration_since(std::time::UNIX_EPOCH)
        .unwrap()
        .subsec_nanos() as u8
}
