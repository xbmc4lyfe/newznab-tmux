//! The internal publication routes against a real database.

#[allow(dead_code)]
#[path = "../../verify/tests/support/mod.rs"]
mod support;

use anyhow::Result;
use axum::Router;
use axum::body::{Body, to_bytes};
use axum::http::{Request, StatusCode};
use nntmux_categorize::Categorizer;
use nntmux_predb::PredbIndex;
use nntmux_releases::internal::{Publication, Settings, routes};
use nntmux_runtime::Ctx;
use nntmux_verify::candidates::{self, Claim};
use nntmux_verify::cleaning::ReleaseCleaner;
use nntmux_verify::locks::{LockSession, WorkerLocks};
use nntmux_verify::manifest::{self, Evidence};
use nntmux_verify::remote::RemotePublisher;
use nntmux_verify::spot_check::{VerificationResult, with_evidence};
use serde_json::{Value, json};
use support::Harness;
use tower::ServiceExt;

const SINGLE: &str = "/internal/release-verification/publish";
const BATCH: &str = "/internal/release-verification/publish-batch";

fn settings() -> Settings {
    Settings {
        enabled: true,
        networks: vec!["127.0.0.0/8".parse().unwrap()],
        batch_items: 3,
        batch_bytes: 4096,
        batch_seconds: 60.0,
    }
}

async fn app(harness: &Harness, settings: Settings) -> Result<Router> {
    let db = harness.db();
    let publication = Publication {
        verify: harness.verify.clone(),
        cleaner: ReleaseCleaner::new(),
        predb: PredbIndex::disabled(),
        categorizer: Categorizer::load(&db).await?,
    };
    let redis = redis::Client::open(std::env::var("TEST_REDIS_URL")?)?
        .get_connection_manager()
        .await?;
    let ctx = Ctx {
        config: std::sync::Arc::new(nntmux_core::Config::from_env()?),
        db,
        redis,
    };
    Ok(routes(settings, Some(publication)).with_state(ctx))
}

async fn post(app: &Router, path: &str, body: &Value) -> Result<(StatusCode, Value)> {
    post_from(app, path, body, "127.0.0.1").await
}

async fn post_from(
    app: &Router,
    path: &str,
    body: &Value,
    client: &str,
) -> Result<(StatusCode, Value)> {
    let request = Request::post(path)
        .header("x-forwarded-for", client)
        .body(Body::from(serde_json::to_vec(body)?))?;
    let response = app.clone().oneshot(request).await?;
    let status = response.status();
    let bytes = to_bytes(response.into_body(), usize::MAX).await?;
    Ok((
        status,
        serde_json::from_slice(&bytes).unwrap_or(Value::Null),
    ))
}

async fn claimed(harness: &Harness, hash: &str) -> Result<Claim> {
    let collection = harness
        .collection(hash, 2, &[("a", 2, &[1, 2]), ("b", 1, &[1])], 1)
        .await?;
    candidates::archive(&harness.verify, collection).await?;
    let mut locks = WorkerLocks::new(&harness.db(), &harness.verify.config).await?;
    assert!(locks.start(1).await?);
    let claim = candidates::claim(&harness.verify, &mut locks, true)
        .await?
        .expect("a due candidate");
    locks.close().await;
    Ok(claim)
}

fn item(claim: &Claim) -> Value {
    json!({
        "claim": {
            "id": claim.candidate.id,
            "version": claim.candidate.version,
            "manifest_hash": claim.candidate.manifest_hash,
            "lease_token": claim.token,
        },
        "reason": "sampled two articles",
        "evidence": {
            "articles": 1,
            "bytes": 100,
            "sampled_files": 1,
            "verified_file_names": ["movie.mkv"],
            "identity_hints": [],
        },
    })
}

async fn release_of(harness: &Harness, candidate: i64) -> Result<Option<i64>> {
    Ok(sqlx::query_scalar(
        "SELECT CAST(release_id AS SIGNED) FROM release_verification_candidates WHERE id = ?",
    )
    .bind(candidate)
    .fetch_one(&harness.db())
    .await?)
}

#[tokio::test]
async fn a_claim_publishes_once_and_a_repeat_finds_the_same_release() -> Result<()> {
    let Some(harness) = Harness::start().await? else {
        return Ok(());
    };
    let app = app(&harness, settings()).await?;
    let claim = claimed(&harness, "aa").await?;
    let body = item(&claim);

    let (status, first) = post(&app, SINGLE, &body).await?;
    assert_eq!(status, StatusCode::OK, "{first}");
    let release = first["release_id"].as_i64().expect("a release");
    assert_eq!(
        release_of(&harness, claim.candidate.id).await?,
        Some(release)
    );

    // The lease is gone now, as it is when a response was lost and the worker resends.
    let (status, second) = post(&app, SINGLE, &body).await?;
    assert_eq!(status, StatusCode::OK, "{second}");
    assert_eq!(second["release_id"].as_i64(), Some(release));
    assert_eq!(harness.count("releases").await?, 1);
    harness.finish().await
}

#[tokio::test]
async fn only_the_lease_holder_can_publish() -> Result<()> {
    let Some(harness) = Harness::start().await? else {
        return Ok(());
    };
    let app = app(&harness, settings()).await?;
    let claim = claimed(&harness, "ab").await?;
    let mut body = item(&claim);
    body["claim"]["lease_token"] = json!("someone else");
    let (status, answer) = post(&app, SINGLE, &body).await?;
    assert_eq!(status, StatusCode::CONFLICT);
    assert!(answer["error"].as_str().unwrap().starts_with("Stale"));
    assert_eq!(harness.count("releases").await?, 0);
    harness.finish().await
}

#[tokio::test]
async fn a_filtered_candidate_answers_without_a_release() -> Result<()> {
    let Some(harness) = Harness::start().await? else {
        return Ok(());
    };
    let app = app(&harness, settings()).await?;
    sqlx::query("UPDATE ingestion_configurations SET min_size_to_form_release = 1000000")
        .execute(&harness.db())
        .await?;
    let claim = claimed(&harness, "ac").await?;
    let (status, answer) = post(&app, SINGLE, &item(&claim)).await?;
    assert_eq!(status, StatusCode::OK, "{answer}");
    assert_eq!(answer, json!({"release_id": null}));
    // A batch resend learns the status the first attempt ended on.
    let batch = json!({"protocol": 1, "items": [item(&claim)]});
    let (status, answer) = post(&app, BATCH, &batch).await?;
    assert_eq!(status, StatusCode::OK);
    assert_eq!(answer["results"][0]["status"], "filtered");
    harness.finish().await
}

#[tokio::test]
async fn publishing_waits_while_the_worker_group_is_held() -> Result<()> {
    let Some(harness) = Harness::start().await? else {
        return Ok(());
    };
    let app = app(&harness, settings()).await?;
    let claim = claimed(&harness, "ad").await?;
    let mut holder = LockSession::open(&harness.db()).await?;
    let held = holder.exclusive_group("worker").await?.expect("the group");
    let (status, answer) = post(&app, SINGLE, &item(&claim)).await?;
    assert_eq!(status, StatusCode::CONFLICT, "{answer}");
    assert!(answer["error"].as_str().unwrap().contains("paused"));
    assert_eq!(harness.count("releases").await?, 0);

    holder.release(held).await;
    let (status, answer) = post(&app, SINGLE, &item(&claim)).await?;
    assert_eq!(status, StatusCode::OK, "{answer}");
    harness.finish().await
}

#[tokio::test]
async fn requests_are_validated() -> Result<()> {
    let Some(harness) = Harness::start().await? else {
        return Ok(());
    };
    let app = app(&harness, settings()).await?;
    let claim = claimed(&harness, "ae").await?;
    let good = item(&claim);
    for (pointer, value) in [
        ("/evidence/articles", json!(0)),
        ("/evidence/articles", json!(17)),
        ("/evidence/bytes", json!(1_048_577)),
        ("/claim/manifest_hash", json!("short")),
        ("/reason", json!("")),
        ("/evidence/verified_file_names", json!(vec!["a"; 17])),
    ] {
        let mut body = good.clone();
        *body.pointer_mut(pointer).unwrap() = value;
        let (status, _) = post(&app, SINGLE, &body).await?;
        assert_eq!(status, StatusCode::UNPROCESSABLE_ENTITY, "{pointer}");
    }
    assert_eq!(harness.count("releases").await?, 0);
    harness.finish().await
}

#[tokio::test]
async fn the_endpoint_is_off_or_private_only() -> Result<()> {
    let Some(harness) = Harness::start().await? else {
        return Ok(());
    };
    let off = app(
        &harness,
        Settings {
            enabled: false,
            ..settings()
        },
    )
    .await?;
    assert_eq!(
        post(&off, SINGLE, &json!({})).await?.0,
        StatusCode::NOT_FOUND
    );
    let on = app(&harness, settings()).await?;
    let (status, _) = post_from(&on, BATCH, &json!({}), "8.8.8.8").await?;
    assert_eq!(status, StatusCode::FORBIDDEN);
    harness.finish().await
}

#[tokio::test]
async fn a_batch_settles_each_item_on_its_own() -> Result<()> {
    let Some(harness) = Harness::start().await? else {
        return Ok(());
    };
    let app = app(&harness, settings()).await?;
    let first = claimed(&harness, "b0").await?;
    let second = claimed(&harness, "b1").await?;
    let mut stale = item(&second);
    stale["claim"]["lease_token"] = json!("someone else");
    let batch = json!({
        "protocol": 1,
        "items": [item(&first), {"claim": {"id": 99}}, stale, ],
    });
    let (status, answer) = post(&app, BATCH, &batch).await?;
    assert_eq!(status, StatusCode::OK, "{answer}");
    assert_eq!(answer["protocol"], 1);
    let results = answer["results"].as_array().unwrap();
    assert_eq!(results[0]["status"], "published");
    assert_eq!(results[0]["claim_id"], first.candidate.id);
    assert!(results[0]["release_id"].is_i64());
    assert_eq!(results[1]["status"], "invalid");
    assert_eq!(results[1]["index"], 1);
    assert_eq!(results[1]["claim_id"], 99);
    assert_eq!(results[2]["status"], "stale");
    assert_eq!(harness.count("releases").await?, 1);
    harness.finish().await
}

#[tokio::test]
async fn a_spent_budget_starts_only_the_first_item() -> Result<()> {
    let Some(harness) = Harness::start().await? else {
        return Ok(());
    };
    let app = app(
        &harness,
        Settings {
            batch_seconds: 0.0,
            ..settings()
        },
    )
    .await?;
    let first = claimed(&harness, "c0").await?;
    let second = claimed(&harness, "c1").await?;
    let batch = json!({"protocol": 1, "items": [item(&first), item(&second)]});
    let (_, answer) = post(&app, BATCH, &batch).await?;
    assert_eq!(answer["results"][0]["status"], "published", "{answer}");
    assert_eq!(answer["results"][1]["status"], "retry");
    assert_eq!(release_of(&harness, second.candidate.id).await?, None);
    harness.finish().await
}

#[tokio::test]
async fn a_batch_outside_its_limits_is_refused_whole() -> Result<()> {
    let Some(harness) = Harness::start().await? else {
        return Ok(());
    };
    let app = app(&harness, settings()).await?;
    let (status, answer) = post(&app, BATCH, &json!({"protocol": 2, "items": [{}]})).await?;
    assert_eq!(status, StatusCode::UNPROCESSABLE_ENTITY);
    assert_eq!(answer["supported_protocol"], 1);
    let (status, answer) = post(&app, BATCH, &json!({"protocol": 1, "items": []})).await?;
    assert_eq!(status, StatusCode::UNPROCESSABLE_ENTITY);
    assert_eq!(answer["max_items"], 3);
    let many = json!({"protocol": 1, "items": [{}, {}, {}, {}]});
    let (status, answer) = post(&app, BATCH, &many).await?;
    assert_eq!(status, StatusCode::UNPROCESSABLE_ENTITY);
    assert_eq!(answer["max_items"], 3);
    let huge = json!({"protocol": 1, "items": ["x".repeat(5000)]});
    let (status, answer) = post(&app, BATCH, &huge).await?;
    assert_eq!(status, StatusCode::PAYLOAD_TOO_LARGE);
    assert_eq!(answer["max_bytes"], 4096);
    harness.finish().await
}

#[tokio::test]
async fn the_remote_client_publishes_through_the_real_route() -> Result<()> {
    let Some(harness) = Harness::start().await? else {
        return Ok(());
    };
    let app = app(&harness, settings()).await?;
    let listener = tokio::net::TcpListener::bind("127.0.0.1:0").await?;
    let address = listener.local_addr()?;
    let server = tokio::spawn(async move { axum::serve(listener, app).await });

    let claim = claimed(&harness, "d0").await?;
    let manifest = manifest::read(
        &harness.db(),
        &harness.verify.config,
        &claim.candidate.manifest_path,
    )
    .await?;
    let evidence = Evidence {
        articles: 1,
        bytes: 100,
        sampled_files: 1,
        verified_file_names: vec!["movie.mkv".to_owned()],
        ..Evidence::default()
    };
    let result = VerificationResult {
        verdict: "sampled".to_owned(),
        reason: "ok".to_owned(),
        manifest: Some(with_evidence(&manifest, &evidence)),
        ..VerificationResult::default()
    };
    let client = RemotePublisher::new(&format!("http://{address}{SINGLE}"), 30)?;
    let release = client.publish(&claim, &result).await?.expect("a release");
    assert_eq!(
        release_of(&harness, claim.candidate.id).await?,
        Some(release)
    );
    // The same request again, as after a response lost on the way back.
    assert_eq!(client.publish(&claim, &result).await?, Some(release));
    assert_eq!(harness.count("releases").await?, 1);
    server.abort();
    harness.finish().await
}

#[tokio::test]
async fn a_partial_below_its_policy_is_deferred() -> Result<()> {
    let Some(harness) = Harness::start_with(|config| config.partial_after_hours = 1).await? else {
        return Ok(());
    };
    let app = app(&harness, settings()).await?;
    let db = harness.db();
    sqlx::query("UPDATE ingestion_configurations SET min_files_to_form_release = 50")
        .execute(&db)
        .await?;
    sqlx::query("UPDATE usenet_groups SET minfilestoformrelease = NULL")
        .execute(&db)
        .await?;
    let collection = harness
        .collection("e0", 2, &[("a", 2, &[1]), ("b", 1, &[1])], 1)
        .await?;
    candidates::archive(&harness.verify, collection).await?;
    sqlx::query(
        "UPDATE release_verification_candidates SET last_unique_at = UTC_TIMESTAMP() - INTERVAL 2 HOUR",
    )
    .execute(&db)
    .await?;
    assert_eq!(candidates::promote_partial(&harness.verify, 10).await?, 1);
    let mut locks = WorkerLocks::new(&db, &harness.verify.config).await?;
    assert!(locks.start(1).await?);
    let claim = candidates::claim(&harness.verify, &mut locks, true)
        .await?
        .expect("a due partial");
    locks.close().await;

    let (status, answer) = post(&app, SINGLE, &item(&claim)).await?;
    assert_eq!(status, StatusCode::CONFLICT, "{answer}");
    assert!(answer["deferred"].is_string(), "{answer}");
    assert_eq!(harness.count("releases").await?, 0);
    harness.finish().await
}
