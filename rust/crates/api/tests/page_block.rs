//! The abuse block on page routes, against real MariaDB and Redis. Skips itself when the services
//! aren't set.

use axum::body::Body;
use axum::http::{Request, StatusCode, header};
use axum::middleware::from_fn;
use axum::routing::get;
use axum::{Extension, Router};
use nntmux_runtime::Ctx;
use nntmux_testkit::TestDb;
use tower::ServiceExt;

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

async fn status(app: &Router, path: &str, user_agent: &str) -> StatusCode {
    let request = Request::get(path)
        .header(header::USER_AGENT, user_agent)
        .body(Body::empty())
        .unwrap();
    app.clone().oneshot(request).await.unwrap().status()
}

#[tokio::test]
async fn streaming_services_are_refused_on_pages_but_not_on_static_files() {
    let Some((_db, ctx)) = fixture().await else {
        return;
    };
    let app = Router::new()
        .route("/login", get(|| async { "page" }))
        .route("/build/app.js", get(|| async { "file" }))
        .layer(from_fn(nntmux_api::block_pages))
        .layer(Extension(ctx));

    assert_eq!(status(&app, "/login", "Mozilla/5.0").await, StatusCode::OK);
    assert_eq!(
        status(&app, "/login", "AIOStreams/2.0").await,
        StatusCode::FORBIDDEN
    );
    assert_eq!(
        status(&app, "/login", "my stremio addon").await,
        StatusCode::FORBIDDEN
    );
    assert_eq!(
        status(&app, "/build/app.js", "AIOStreams/2.0").await,
        StatusCode::OK
    );
}
