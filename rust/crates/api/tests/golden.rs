//! Replays requests recorded from the PHP reference (`fixtures/record.py`) and compares the bytes.
//! Needs TEST_DATABASE_URL and TEST_REDIS_URL; without them each test skips itself.

use std::fs;
use std::path::PathBuf;
use std::sync::Arc;

use anyhow::{Context, Result};
use axum::Router;
use axum::body::{Body, to_bytes};
use axum::http::Request;
use base64::Engine;
use nntmux_api::{Settings, router_with};
use nntmux_core::{Config, DatabaseConfig, ManticoreConfig};
use nntmux_runtime::Ctx;
use nntmux_testkit::TestDb;
use rand::Rng;
use redis::aio::ConnectionManager;
use serde_json::Value;
use sqlx::{AssertSqlSafe, MySqlPool};
use tower::ServiceExt;

/// PHP streams JSON without a length; the two stacks may differ only here.
const IGNORED_HEADERS: [&str; 2] = ["content-length", "expires"];

fn fixtures() -> PathBuf {
    PathBuf::from(env!("CARGO_MANIFEST_DIR")).join("tests/fixtures")
}

fn test_config() -> Config {
    Config {
        database: DatabaseConfig {
            host: String::new(),
            port: 0,
            database: String::new(),
            username: String::new(),
            password: String::new(),
            ssl_ca: None,
            max_connections: 4,
        },
        redis_url: String::new(),
        manticore: ManticoreConfig {
            host: String::new(),
            sql_port: 0,
            http_port: 0,
        },
        health_addr: "127.0.0.1:0".parse().expect("static address"),
        log_filter: String::new(),
        log_json: false,
    }
}

async fn run_sql(pool: &MySqlPool, sql: &str) -> Result<()> {
    sqlx::raw_sql(AssertSqlSafe(sql.to_owned()))
        .execute(pool)
        .await
        .with_context(|| sql.to_owned())?;
    Ok(())
}

fn settings() -> Settings {
    let prefix: u64 = rand::rng().random();
    let mut settings = Settings::new(
        "NNTmux".to_owned(),
        "admin@example.test".to_owned(),
        500,
        250,
        8192,
        60,
        format!("t{prefix:016x}:"),
    );
    settings.abuse.asn_lookup = false;
    settings
}

fn settings_with_cap() -> Settings {
    let mut capped = settings();
    capped.max_limit = 1;
    capped
}

fn app(ctx: &Ctx) -> Router {
    router_with(settings()).with_state(ctx.clone())
}

/// Volatile response parts become placeholders on both sides before comparing.
fn mask(value: &str) -> String {
    let date = regex::Regex::new(r#"("(?:apiOldestTime|grabOldestTime|apioldesttime|graboldesttime)":|(?:apioldesttime|graboldesttime)=)"[^"]+""#)
        .expect("static regex");
    date.replace_all(value, "$1\"<now>\"").into_owned()
}

fn headers_of(value: &Value) -> Vec<(String, String)> {
    let mut headers: Vec<(String, String)> = value
        .as_array()
        .expect("recorded headers")
        .iter()
        .map(|pair| {
            (
                pair[0].as_str().expect("name").to_owned(),
                pair[1].as_str().expect("value").to_owned(),
            )
        })
        .filter(|(name, _)| !IGNORED_HEADERS.contains(&name.as_str()))
        .collect();
    headers.sort();
    headers
}

async fn replay(file: &str, skip: &[&str]) -> Result<()> {
    let _ = tracing_subscriber::fmt().with_test_writer().try_init();
    let (Some(db), Ok(redis_url)) = (TestDb::create().await?, std::env::var("TEST_REDIS_URL"))
    else {
        eprintln!("test services unset, skipping");
        return Ok(());
    };
    let redis = ConnectionManager::new(redis::Client::open(redis_url)?).await?;
    let ctx = Ctx {
        config: Arc::new(test_config()),
        db: db.pool.clone(),
        redis,
    };
    let seed = fs::read_to_string(fixtures().join("seed.sql"))?;
    let cases: Vec<Value> = serde_json::from_str(&fs::read_to_string(fixtures().join(file))?)?;
    let mut failures = Vec::new();
    for case in &cases {
        let name = case["name"].as_str().context("name")?;
        if skip.contains(&name) {
            continue;
        }
        run_sql(&db.pool, &seed).await?;
        for statement in case["setup"].as_array().into_iter().flatten() {
            run_sql(&db.pool, statement.as_str().context("setup")?).await?;
        }
        let mut builder = Request::builder()
            .method(case["method"].as_str().unwrap_or("GET"))
            .uri(case["path"].as_str().context("path")?)
            .header("accept", "*/*");
        if case["headers"]["User-Agent"].is_null() {
            builder = builder.header("user-agent", "golden");
        }
        if case["headers"]["Host"].is_null() {
            builder = builder.header("host", "127.0.0.1:8765");
        }
        if let Some(headers) = case["headers"].as_object() {
            for (header, value) in headers {
                builder = builder.header(header.to_lowercase(), value.as_str().context("header")?);
            }
        }
        let body = match (case["body"].as_str(), case["body_b64"].as_str()) {
            (Some(text), _) => Body::from(text.to_owned()),
            (_, Some(encoded)) => {
                Body::from(base64::engine::general_purpose::STANDARD.decode(encoded)?)
            }
            _ => Body::empty(),
        };
        let response = app(&ctx).oneshot(builder.body(body)?).await?;
        let status = response.status().as_u16();
        let mut got_headers: Vec<(String, String)> = response
            .headers()
            .iter()
            .map(|(name, value)| {
                (
                    name.to_string(),
                    value.to_str().unwrap_or_default().to_owned(),
                )
            })
            .filter(|(name, _)| !IGNORED_HEADERS.contains(&name.as_str()))
            .collect();
        got_headers.sort();
        let got_body = to_bytes(response.into_body(), usize::MAX).await?;
        let got_body = String::from_utf8_lossy(&got_body).into_owned();

        let expect = &case["expect"];
        let mut want_headers = headers_of(&expect["headers"]);
        // PHP's server adds its default type to empty responses; nothing reads it.
        if status == 204 || status == 304 {
            want_headers.retain(|(name, _)| name != "content-type");
        }
        // HEAD went over a real socket for PHP and an in-process call here.
        let want_body = if case["method"] == "HEAD" {
            String::new()
        } else {
            expect["body"].as_str().unwrap_or_default().to_owned()
        };
        let got_body = if case["method"] == "HEAD" {
            String::new()
        } else {
            got_body
        };

        let mut problems = Vec::new();
        if i64::from(status) != expect["status"].as_i64().unwrap_or_default() {
            problems.push(format!("status {status} != {}", expect["status"]));
        }
        let status_only = case["status_only"] == Value::Bool(true);
        if !status_only
            && case["ignore_body"] != Value::Bool(true)
            && mask(&got_body) != mask(&want_body)
        {
            problems.push(first_difference(&mask(&want_body), &mask(&got_body)));
        }
        let got_cmp: Vec<_> = got_headers
            .iter()
            .filter(|(name, _)| case["ignore_body"] != Value::Bool(true) || name != "content-type")
            .collect();
        let want_cmp: Vec<_> = want_headers
            .iter()
            .filter(|(name, _)| case["ignore_body"] != Value::Bool(true) || name != "content-type")
            .collect();
        if !status_only && got_cmp != want_cmp {
            problems.push(format!("headers\n  want {want_cmp:?}\n  got  {got_cmp:?}"));
        }
        let audit: Vec<String> = sqlx::query_scalar(
            "SELECT request FROM user_requests WHERE request <> 'x' ORDER BY id",
        )
        .fetch_all(&db.pool)
        .await?;
        let want_audit: Vec<String> = expect["audit"]
            .as_array()
            .into_iter()
            .flatten()
            .map(|entry| entry.as_str().unwrap_or_default().to_owned())
            .collect();
        if audit != want_audit {
            problems.push(format!("audit\n  want {want_audit:?}\n  got  {audit:?}"));
        }
        if let Some(statement) = case["after"].as_str() {
            let rows: Vec<String> = sqlx::query_scalar(AssertSqlSafe(statement.to_owned()))
                .fetch_all(&db.pool)
                .await?;
            if Value::from(rows.clone()) != expect["after"] {
                problems.push(format!("after: want {} got {rows:?}", expect["after"]));
            }
        }
        if !problems.is_empty() {
            failures.push(format!("{name}: {}", problems.join("; ")));
        }
    }
    db.drop_database().await?;
    assert!(
        failures.is_empty(),
        "{} of {} cases differ:\n{}",
        failures.len(),
        cases.len(),
        failures.join("\n")
    );
    Ok(())
}

fn first_difference(want: &str, got: &str) -> String {
    let at = want
        .bytes()
        .zip(got.bytes())
        .position(|(a, b)| a != b)
        .unwrap_or_else(|| want.len().min(got.len()));
    let around = |text: &str| {
        let bytes = text.as_bytes();
        let start = at.saturating_sub(60);
        let end = (at + 100).min(bytes.len());
        String::from_utf8_lossy(&bytes[start.min(end)..end]).into_owned()
    };
    format!(
        "body differs at byte {at}\n  want …{}\n  got  …{}",
        around(want),
        around(got)
    )
}

/// The token limiter's 429 can't be recorded: the reference runs on a per-request array cache.
/// `poster` has a rate limit of 2 in the seed. Shape from `ThrottleApiRequestsByToken`.
#[tokio::test]
async fn token_rate_limit_answers_429() -> Result<()> {
    let (Some(db), Ok(redis_url)) = (TestDb::create().await?, std::env::var("TEST_REDIS_URL"))
    else {
        return Ok(());
    };
    let redis = ConnectionManager::new(redis::Client::open(redis_url)?).await?;
    let ctx = Ctx {
        config: Arc::new(test_config()),
        db: db.pool.clone(),
        redis,
    };
    run_sql(&db.pool, &fs::read_to_string(fixtures().join("seed.sql"))?).await?;
    let app = app(&ctx);
    let call = || {
        let request = Request::builder()
            .uri("/api/v2/movies?api_token=tokenposter")
            .header("host", "x.test")
            .body(Body::empty());
        app.clone().oneshot(request.expect("static request"))
    };
    for remaining in ["1", "0"] {
        let response = call().await?;
        assert_eq!(response.status(), 400);
        assert_eq!(response.headers()["x-ratelimit-limit"], "2");
        assert_eq!(response.headers()["x-ratelimit-remaining"], remaining);
    }
    let response = call().await?;
    assert_eq!(response.status(), 429);
    assert_eq!(response.headers()["x-ratelimit-remaining"], "0");
    assert_eq!(
        response.headers()["x-nntmux"],
        "API ERROR [500] Request limit reached"
    );
    let retry = response.headers()["retry-after"].to_str()?.to_owned();
    let body = to_bytes(response.into_body(), usize::MAX).await?;
    assert_eq!(
        String::from_utf8(body.to_vec())?,
        format!(r#"{{"error":"Request limit reached","retry_after":{retry}}}"#)
    );
    db.drop_database().await
}

/// Sending a new Host per request must not mint a new bucket for the per-IP throttle.
#[tokio::test]
async fn group_throttle_ignores_host() -> Result<()> {
    let (Some(db), Ok(redis_url)) = (TestDb::create().await?, std::env::var("TEST_REDIS_URL"))
    else {
        return Ok(());
    };
    let redis = ConnectionManager::new(redis::Client::open(redis_url)?).await?;
    let ctx = Ctx {
        config: Arc::new(test_config()),
        db: db.pool.clone(),
        redis,
    };
    let app = app(&ctx);
    for (host, remaining) in [("a.test", "59"), ("b.test", "58")] {
        let request = Request::builder()
            .uri("/api/nope")
            .header("host", host)
            .body(Body::empty())?;
        let response = app.clone().oneshot(request).await?;
        assert_eq!(response.headers()["x-ratelimit-remaining"], remaining);
    }
    db.drop_database().await
}

/// The `UpdateUserApiAccess` job writes at most once per interval.
#[tokio::test]
async fn access_update_runs_once_per_interval() -> Result<()> {
    let (Some(db), Ok(redis_url)) = (TestDb::create().await?, std::env::var("TEST_REDIS_URL"))
    else {
        return Ok(());
    };
    let redis = ConnectionManager::new(redis::Client::open(redis_url)?).await?;
    let ctx = Ctx {
        config: Arc::new(test_config()),
        db: db.pool.clone(),
        redis,
    };
    run_sql(&db.pool, &fs::read_to_string(fixtures().join("seed.sql"))?).await?;
    let settings = settings();
    let read = || {
        sqlx::query_as::<_, (Option<String>, Option<String>)>(
            "SELECT DATE_FORMAT(apiaccess, '%Y-%m-%d %H:%i:%s'), host FROM users WHERE id = 1",
        )
        .fetch_one(&db.pool)
    };
    nntmux_api::usage::update_user_api_access(
        &ctx,
        &settings,
        1,
        Some("1.2.3.4"),
        "2026-01-01 00:00:00",
    )
    .await?;
    assert_eq!(
        read().await?,
        (
            Some("2026-01-01 00:00:00".to_owned()),
            Some("1.2.3.4".to_owned())
        )
    );
    nntmux_api::usage::update_user_api_access(
        &ctx,
        &settings,
        1,
        Some("5.6.7.8"),
        "2026-01-02 00:00:00",
    )
    .await?;
    assert_eq!(
        read().await?,
        (
            Some("2026-01-01 00:00:00".to_owned()),
            Some("1.2.3.4".to_owned())
        )
    );
    db.drop_database().await
}

#[tokio::test]
async fn foundation_matches_php() -> Result<()> {
    replay("golden_foundation.json", &[]).await
}

#[tokio::test]
async fn releases_match_php() -> Result<()> {
    replay("golden_releases.json", &[]).await
}

#[tokio::test]
async fn getnzb_matches_php() -> Result<()> {
    replay("golden_getnzb.json", &[]).await
}

/// The archive holds each stored NZB under its release name, unmodified, skipping unknown GUIDs.
#[tokio::test]
async fn zip_download_contains_each_nzb() -> Result<()> {
    let (Some(db), Ok(redis_url)) = (TestDb::create().await?, std::env::var("TEST_REDIS_URL"))
    else {
        return Ok(());
    };
    let redis = ConnectionManager::new(redis::Client::open(redis_url)?).await?;
    let ctx = Ctx {
        config: Arc::new(test_config()),
        db: db.pool.clone(),
        redis,
    };
    run_sql(&db.pool, &fs::read_to_string(fixtures().join("seed.sql"))?).await?;
    let cases: Vec<Value> =
        serde_json::from_str(&fs::read_to_string(fixtures().join("golden_getnzb.json"))?)?;
    let case = cases
        .iter()
        .find(|case| case["name"] == "getnzb_zip_ok")
        .context("case")?;
    for statement in case["setup"].as_array().into_iter().flatten() {
        run_sql(&db.pool, statement.as_str().context("setup")?).await?;
    }
    let request = Request::builder()
        .uri(case["path"].as_str().context("path")?)
        .header("host", "x.test")
        .body(Body::empty())?;
    let response = app(&ctx).oneshot(request).await?;
    assert_eq!(response.status(), 200);
    assert_eq!(response.headers()["content-type"], "application/x-zip");
    let bytes = to_bytes(response.into_body(), usize::MAX).await?;
    let mut archive = zip::ZipArchive::new(std::io::Cursor::new(bytes.to_vec()))?;
    assert_eq!(archive.len(), 1);
    let mut file = archive.by_name("A.Movie.2024.1080p.BluRay-GRP.nzb")?;
    let mut contents = String::new();
    std::io::Read::read_to_string(&mut file, &mut contents)?;
    assert!(contents.contains("file poster=\"a@b\""));
    db.drop_database().await
}

/// `limit` is left out: PHP passes the raw string to an `int` parameter under strict types, so any
/// numeric `limit` answers 500 there. Rust honors it, which `rss_limit_is_honored` covers.
#[tokio::test]
async fn rss_matches_php() -> Result<()> {
    replay("golden_rss.json", &["rss_limit_param"]).await
}

/// PHP crashes on a numeric `limit`; the feed should simply honor it.
#[tokio::test]
async fn rss_limit_is_honored() -> Result<()> {
    let (Some(db), Ok(redis_url)) = (TestDb::create().await?, std::env::var("TEST_REDIS_URL"))
    else {
        return Ok(());
    };
    let redis = ConnectionManager::new(redis::Client::open(redis_url)?).await?;
    let ctx = Ctx {
        config: Arc::new(test_config()),
        db: db.pool.clone(),
        redis,
    };
    run_sql(&db.pool, &fs::read_to_string(fixtures().join("seed.sql"))?).await?;
    // -1 reads as the minimum page, and a huge value stops at the cap.
    for (uri, app, items) in [
        ("/rss/full-feed?limit=2&api_token=tokenalice", app(&ctx), 2),
        ("/rss/full-feed?limit=-1&api_token=tokenalice", app(&ctx), 1),
        (
            "/rss/full-feed?limit=1e30&api_token=tokenalice",
            router_with(settings_with_cap()).with_state(ctx.clone()),
            1,
        ),
    ] {
        let request = Request::builder()
            .uri(uri)
            .header("host", "x.test")
            .body(Body::empty())?;
        let response = app.oneshot(request).await?;
        assert_eq!(response.status(), 200);
        let body = String::from_utf8(to_bytes(response.into_body(), usize::MAX).await?.to_vec())?;
        assert_eq!(body.matches("<item>").count(), items, "{uri}");
    }
    db.drop_database().await
}

/// Same score PHP's detector gives: the UA pair (25) and IP correlation (20) alone stay under the
/// default threshold of 50, and the indexer Referer (30) tips it over.
#[tokio::test]
async fn proxy_detection_scores_like_php() -> Result<()> {
    let (Some(db), Ok(redis_url)) = (TestDb::create().await?, std::env::var("TEST_REDIS_URL"))
    else {
        return Ok(());
    };
    let redis = ConnectionManager::new(redis::Client::open(redis_url)?).await?;
    let ctx = Ctx {
        config: Arc::new(test_config()),
        db: db.pool.clone(),
        redis,
    };
    run_sql(&db.pool, &fs::read_to_string(fixtures().join("seed.sql"))?).await?;
    let mut settings = settings();
    settings.abuse.detection_enabled = true;
    let app = router_with(settings).with_state(ctx.clone());
    let call = |uri: &str, referer: Option<&str>| {
        let mut builder = Request::builder()
            .uri(uri)
            .header("host", "x.test")
            .header("user-agent", "Prowlarr/1.0")
            .header("x-forwarded-for", "203.0.113.9");
        if let Some(referer) = referer {
            builder = builder.header("referer", referer);
        }
        app.clone()
            .oneshot(builder.body(Body::empty()).expect("static request"))
    };
    let response = call("/api/v1/api?t=caps&apikey=tokenalice", None).await?;
    assert_eq!(response.status(), 200);
    let download = "/api/v1/api?t=g&apikey=tokenalice&id=nope";
    assert_eq!(call(download, None).await?.status(), 404);
    let refused = call(download, Some("http://hydra.local:5076/")).await?;
    assert_eq!(refused.status(), 403);
    let body = to_bytes(refused.into_body(), usize::MAX).await?;
    assert!(String::from_utf8(body.to_vec())?.contains("Proxying NZB downloads"));
    db.drop_database().await
}
