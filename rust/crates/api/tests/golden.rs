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
const IGNORED_HEADERS: [&str; 1] = ["content-length"];

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
    sqlx::raw_sql(AssertSqlSafe(sql.to_owned())).execute(pool).await.with_context(|| sql.to_owned())?;
    Ok(())
}

fn settings() -> Settings {
    let prefix: u64 = rand::rng().random();
    Settings::new(
        "NNTmux".to_owned(),
        "admin@example.test".to_owned(),
        500,
        250,
        8192,
        60,
        format!("t{prefix:016x}:"),
    )
}

fn app(ctx: &Ctx) -> Router {
    router_with(settings()).with_state(ctx.clone())
}

/// Volatile response parts become placeholders on both sides before comparing.
fn mask(value: &str) -> String {
    let date = regex::Regex::new(r#"("api(?:Oldest|oldest)Time":|apioldesttime=)"[^"]*""#).expect("static regex");
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
    let (Some(db), Ok(redis_url)) = (TestDb::create().await?, std::env::var("TEST_REDIS_URL")) else {
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
            .header("host", "127.0.0.1:8765")
            .header("accept", "*/*")
            .header("user-agent", "golden");
        if let Some(headers) = case["headers"].as_object() {
            for (header, value) in headers {
                builder = builder.header(header.to_lowercase(), value.as_str().context("header")?);
            }
        }
        let body = match (case["body"].as_str(), case["body_b64"].as_str()) {
            (Some(text), _) => Body::from(text.to_owned()),
            (_, Some(encoded)) => Body::from(base64::engine::general_purpose::STANDARD.decode(encoded)?),
            _ => Body::empty(),
        };
        let response = app(&ctx).oneshot(builder.body(body)?).await?;
        let status = response.status().as_u16();
        let mut got_headers: Vec<(String, String)> = response
            .headers()
            .iter()
            .map(|(name, value)| (name.to_string(), value.to_str().unwrap_or_default().to_owned()))
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
        let want_body = if case["method"] == "HEAD" { String::new() } else { expect["body"].as_str().unwrap_or_default().to_owned() };
        let got_body = if case["method"] == "HEAD" { String::new() } else { got_body };

        let mut problems = Vec::new();
        if i64::from(status) != expect["status"].as_i64().unwrap_or_default() {
            problems.push(format!("status {status} != {}", expect["status"]));
        }
        if case["ignore_body"] != Value::Bool(true) && mask(&got_body) != mask(&want_body) {
            problems.push(first_difference(&mask(&want_body), &mask(&got_body)));
        }
        let got_cmp: Vec<_> = got_headers.iter().filter(|(name, _)| case["ignore_body"] != Value::Bool(true) || name != "content-type").collect();
        let want_cmp: Vec<_> = want_headers.iter().filter(|(name, _)| case["ignore_body"] != Value::Bool(true) || name != "content-type").collect();
        if got_cmp != want_cmp {
            problems.push(format!("headers\n  want {want_cmp:?}\n  got  {got_cmp:?}"));
        }
        let audit: Vec<String> = sqlx::query_scalar("SELECT request FROM user_requests WHERE request <> 'x' ORDER BY id")
            .fetch_all(&db.pool)
            .await?;
        let want_audit: Vec<String> = expect["audit"].as_array().into_iter().flatten().map(|entry| entry.as_str().unwrap_or_default().to_owned()).collect();
        if audit != want_audit {
            problems.push(format!("audit\n  want {want_audit:?}\n  got  {audit:?}"));
        }
        if !problems.is_empty() {
            failures.push(format!("{name}: {}", problems.join("; ")));
        }
    }
    db.drop_database().await?;
    assert!(failures.is_empty(), "{} of {} cases differ:\n{}", failures.len(), cases.len(), failures.join("\n"));
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
    format!("body differs at byte {at}\n  want …{}\n  got  …{}", around(want), around(got))
}

#[tokio::test]
async fn foundation_matches_php() -> Result<()> {
    replay("golden_foundation.json", &[]).await
}
