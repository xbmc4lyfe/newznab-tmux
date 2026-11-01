//! The candidate worker against a real database, and its NNTP source against a real socket.

#[allow(dead_code)]
mod support;

use std::collections::HashMap;
use std::sync::Arc;
use std::time::Duration;

use anyhow::{Result, anyhow};
use async_trait::async_trait;
use nntmux_nntp::{NntpConfig, Provider, TlsOptions};
use nntmux_runtime::Shutdown;
use nntmux_verify::candidates;
use nntmux_verify::spot_check::{ArticleBudgetExceeded, ArticleSource};
use nntmux_verify::worker::{NntpSource, RunOptions, Worker};
use serde_json::{Value, json};
use support::Harness;
use tokio::io::{AsyncBufReadExt, AsyncWriteExt, BufReader};
use tokio::net::TcpListener;
use wiremock::matchers::method;
use wiremock::{Mock, MockServer, ResponseTemplate};

fn article(name: &str, payload: &[u8]) -> Vec<u8> {
    let encoded: Vec<u8> = payload
        .iter()
        .flat_map(|byte| match byte.wrapping_add(42) {
            escape @ (0 | 10 | 13 | 61) => vec![b'=', escape.wrapping_add(64)],
            plain => vec![plain],
        })
        .collect();
    let size = payload.len();
    let crc = format!("{:08x}", crc32fast::hash(payload));
    let mut raw = format!(
        "=ybegin part=1 total=1 line=128 size={size} name={name}\r\n=ypart begin=1 end={size}\r\n"
    )
    .into_bytes();
    raw.extend(encoded);
    raw.extend(format!("\r\n=yend size={size} part=1 pcrc32={crc}\r\n").into_bytes());
    raw
}

#[derive(Default)]
struct FakeSource {
    articles: HashMap<String, Vec<u8>>,
}

#[async_trait]
impl ArticleSource for FakeSource {
    async fn fetch_bounded(&self, message_id: &str, _: i64) -> Result<Vec<u8>> {
        self.articles
            .get(message_id)
            .cloned()
            .ok_or_else(|| anyhow!("article is unavailable"))
    }

    async fn stat(&self, _: &[String]) -> Result<()> {
        Ok(())
    }
}

fn nntp_config(port: u16) -> Arc<NntpConfig> {
    Arc::new(NntpConfig {
        primary: Provider {
            host: "127.0.0.1".to_owned(),
            port,
            tls: false,
            username: String::new(),
            password: String::new(),
            socket_timeout: Duration::from_secs(5),
        },
        alternate: None,
        tls: TlsOptions::default(),
        primary_connections: 1,
        alternate_connections: 0,
        compressed_headers: false,
        use_alternate: false,
        alternate_article_fallback: false,
        fingerprint_breaker_seconds: 900,
        retries: 1,
    })
}

const ID: &str = "msg-aa-a-1@example.com";

async fn queued(harness: &Harness) -> Result<i64> {
    let collection = harness.collection("aa", 1, &[("a", 1, &[1])], 1).await?;
    candidates::archive(&harness.verify, collection).await?;
    Ok(collection)
}

fn once() -> RunOptions {
    RunOptions {
        limit: 5,
        slot: 1,
        max_seconds: 0,
    }
}

#[tokio::test]
async fn a_verified_candidate_publishes_and_a_failed_one_retries() -> Result<()> {
    let Some(harness) = Harness::start().await? else {
        return Ok(());
    };
    let worker = Worker::for_tests(harness.verify.clone(), nntp_config(1)).await?;
    let stop = Shutdown::default();
    sqlx::query("UPDATE ingestion_configurations SET min_files_to_form_release = 1")
        .execute(&harness.db())
        .await?;
    sqlx::query("UPDATE usenet_groups SET minfilestoformrelease = NULL")
        .execute(&harness.db())
        .await?;
    queued(&harness).await?;

    // The article is unavailable: the attempt is recorded for a retry, and nothing is published.
    let report = worker.run(&FakeSource::default(), once(), &stop).await?;
    assert_eq!((report.started, report.processed), (true, 1));
    assert_eq!(harness.count("releases").await?, 0);
    let (status, reason, leased): (String, Option<String>, Option<String>) =
        sqlx::query_as("SELECT status, reason, lease_token FROM release_verification_candidates")
            .fetch_one(&harness.db())
            .await?;
    assert_eq!(status, "unknown");
    assert!(reason.is_some_and(|reason| !reason.is_empty()));
    assert_eq!(leased, None);

    // Due again, it passes this time.
    sqlx::query("UPDATE release_verification_candidates SET next_attempt_at = UTC_TIMESTAMP()")
        .execute(&harness.db())
        .await?;
    let mut source = FakeSource::default();
    source
        .articles
        .insert(ID.to_owned(), article("a.rar", &[7; 100]));
    let report = worker.run(&source, once(), &stop).await?;
    assert_eq!(report.processed, 1);
    let state: Vec<(String, Option<String>)> =
        sqlx::query_as("SELECT status, reason FROM release_verification_candidates")
            .fetch_all(&harness.db())
            .await?;
    assert_eq!(harness.count("releases").await?, 1, "{state:?}");
    let status: (String, Option<String>) =
        sqlx::query_as("SELECT status, reason FROM release_verification_candidates")
            .fetch_one(&harness.db())
            .await?;
    assert_eq!(status.0, "sampled", "{status:?}");
    assert!(
        harness
            .verify
            .config
            .storage_path
            .join("status.json")
            .exists()
    );

    // Nothing left to claim.
    let report = worker.run(&source, once(), &stop).await?;
    assert_eq!(report.processed, 0);
    harness.finish().await
}

#[tokio::test]
async fn a_held_pause_keeps_workers_out() -> Result<()> {
    let Some(harness) = Harness::start().await? else {
        return Ok(());
    };
    let worker = Worker::for_tests(harness.verify.clone(), nntp_config(1)).await?;
    queued(&harness).await?;
    nntmux_verify::locks::pause(&harness.db(), "deploy", "deployment").await?;
    let report = worker
        .run(&FakeSource::default(), once(), &Shutdown::default())
        .await?;
    assert_eq!(report.processed, 0);
    assert_eq!(harness.count("releases").await?, 0);
    harness.finish().await
}

#[tokio::test]
async fn terminal_scratch_is_removed_and_live_scratch_stays() -> Result<()> {
    let Some(harness) = Harness::start().await? else {
        return Ok(());
    };
    let db = harness.db();
    let worker = Worker::for_tests(harness.verify.clone(), nntp_config(1)).await?;
    let root = harness.verify.config.storage_path.join("scratch");
    std::fs::create_dir_all(root.join("7-1/nested"))?;
    std::fs::write(root.join("7-1/nested/file"), b"x")?;
    std::fs::create_dir_all(root.join("8-1"))?;
    sqlx::query("DELETE FROM release_verification_scratch_budget")
        .execute(&db)
        .await?;
    sqlx::query("INSERT INTO release_verification_scratch_budget (id, reserved_bytes, initialized) VALUES (1, 50, 1)")
        .execute(&db)
        .await?;
    sqlx::query(
        "INSERT INTO release_verification_scratch (candidate_id, version, terminal, reserved_bytes) VALUES (7, 1, 1, 50), (8, 1, 0, 0), (9, 1, 0, 0)",
    )
    .execute(&db)
    .await?;
    worker
        .run(&FakeSource::default(), once(), &Shutdown::default())
        .await?;
    assert!(!root.join("7-1").exists(), "terminal scratch is removed");
    assert!(
        root.join("8-1").exists(),
        "a candidate that may still run keeps its scratch"
    );
    let rows: Vec<i64> = sqlx::query_scalar(
        "SELECT CAST(candidate_id AS SIGNED) FROM release_verification_scratch ORDER BY candidate_id",
    )
    .fetch_all(&db)
    .await?;
    assert_eq!(rows, vec![8], "absent directories are forgotten");
    let reserved: u64 =
        sqlx::query_scalar("SELECT reserved_bytes FROM release_verification_scratch_budget")
            .fetch_one(&db)
            .await?;
    assert_eq!(reserved, 0);
    std::fs::remove_dir_all(harness.verify.config.storage_path.clone())?;
    harness.finish().await
}

/// Answers BODY and STAT for what it holds, on one connection at a time.
async fn serve(articles: HashMap<String, Vec<u8>>) -> u16 {
    let listener = TcpListener::bind("127.0.0.1:0").await.unwrap();
    let port = listener.local_addr().unwrap().port();
    tokio::spawn(async move {
        loop {
            let Ok((stream, _)) = listener.accept().await else {
                return;
            };
            let articles = articles.clone();
            tokio::spawn(async move {
                let mut stream = BufReader::new(stream);
                stream.write_all(b"200 ready\r\n").await.unwrap();
                loop {
                    let mut line = String::new();
                    if stream.read_line(&mut line).await.unwrap_or(0) == 0 {
                        return;
                    }
                    let line = line.trim().to_owned();
                    let id = line
                        .split_once('<')
                        .map(|(_, rest)| rest.trim_end_matches('>').to_owned());
                    let quoted = id.clone().unwrap_or_default();
                    let reply = match (
                        line.split(' ').next(),
                        id.and_then(|id| articles.get(&id).cloned()),
                    ) {
                        (Some("BODY"), Some(body)) => {
                            let mut reply = format!("222 0 <{quoted}> body\r\n").into_bytes();
                            reply.extend(body);
                            reply.extend(b".\r\n");
                            reply
                        }
                        (Some("STAT"), Some(_)) => {
                            format!("223 0 <{quoted}> status\r\n").into_bytes()
                        }
                        (Some("BODY" | "STAT"), None) => b"430 no such article\r\n".to_vec(),
                        (Some("QUIT"), _) => {
                            let _ = stream.write_all(b"205 bye\r\n").await;
                            return;
                        }
                        _ => b"500 what\r\n".to_vec(),
                    };
                    stream.write_all(&reply).await.unwrap();
                }
            });
        }
    });
    port
}

#[tokio::test]
async fn the_nntp_source_reads_bounded_bodies_and_stats() -> Result<()> {
    let Some(harness) = Harness::start().await? else {
        return Ok(());
    };
    let body = article("a.rar", &[7; 100]);
    let large = vec![b'x'; 2_000];
    let port = serve(HashMap::from([
        ("a@x".to_owned(), body.clone()),
        (
            "big@x".to_owned(),
            [large.clone(), b"\r\n".to_vec()].concat(),
        ),
    ]))
    .await;
    let source = NntpSource::new(nntp_config(port), &harness.verify);

    assert_eq!(source.fetch_bounded("a@x", 10_000).await?, body);
    let missing = source.fetch_bounded("gone@x", 10_000).await;
    assert!(missing.is_err());
    // The connection survives a missing article and the budget stops a large one.
    let too_big = source
        .fetch_bounded("big@x", 100)
        .await
        .expect_err("over budget");
    assert!(too_big.is::<ArticleBudgetExceeded>(), "{too_big:#}");
    assert!(source.fetch_bounded("a@x", 0).await.is_err());

    source.stat(&["a@x".to_owned()]).await?;
    assert!(source.stat(&["gone@x".to_owned()]).await.is_err());
    assert!(source.stat(&vec!["a@x".to_owned(); 17]).await.is_err());
    harness.finish().await
}

#[tokio::test]
async fn an_unreachable_server_fails_the_attempt_not_the_run() -> Result<()> {
    let Some(harness) = Harness::start().await? else {
        return Ok(());
    };
    let listener = TcpListener::bind("127.0.0.1:0").await?;
    let port = listener.local_addr()?.port();
    drop(listener);
    let worker = Worker::for_tests(harness.verify.clone(), nntp_config(port)).await?;
    queued(&harness).await?;
    let source = NntpSource::new(worker.nntp.clone(), &harness.verify);
    let report = worker.run(&source, once(), &Shutdown::default()).await?;
    assert_eq!(report.processed, 1);
    let status: String = sqlx::query_scalar("SELECT status FROM release_verification_candidates")
        .fetch_one(&harness.db())
        .await?;
    assert_eq!(status, "unknown");
    harness.finish().await
}

/// A remote worker with this candidate's article, publishing through `server`.
async fn remote_run(harness: &Harness, server: &MockServer) -> Result<()> {
    sqlx::query("UPDATE ingestion_configurations SET min_files_to_form_release = 1")
        .execute(&harness.db())
        .await?;
    sqlx::query("UPDATE usenet_groups SET minfilestoformrelease = NULL")
        .execute(&harness.db())
        .await?;
    queued(harness).await?;
    let verify = harness.with_config(|config| config.publish_url = Some(server.uri()));
    let worker = Worker::for_tests(verify, nntp_config(1)).await?;
    let mut source = FakeSource::default();
    source
        .articles
        .insert(ID.to_owned(), article("a.rar", &[7; 100]));
    let report = worker.run(&source, once(), &Shutdown::default()).await?;
    assert_eq!(report.processed, 1);
    Ok(())
}

async fn candidate_state(harness: &Harness) -> Result<(String, Option<String>, Option<String>)> {
    Ok(
        sqlx::query_as("SELECT status, reason, lease_token FROM release_verification_candidates")
            .fetch_one(&harness.db())
            .await?,
    )
}

#[tokio::test]
async fn a_remote_worker_sends_only_evidence_and_never_writes_the_release() -> Result<()> {
    let Some(harness) = Harness::start().await? else {
        return Ok(());
    };
    let server = MockServer::start().await;
    Mock::given(method("POST"))
        .respond_with(ResponseTemplate::new(200).set_body_json(json!({"release_id": 77})))
        .expect(1)
        .mount(&server)
        .await;
    remote_run(&harness, &server).await?;
    assert_eq!(harness.count("releases").await?, 0);

    let sent = server.received_requests().await.unwrap();
    let body: Value = serde_json::from_slice(&sent[0].body)?;
    assert!(body["claim"]["version"].is_i64());
    assert_eq!(body["claim"]["manifest_hash"].as_str().unwrap().len(), 64);
    assert!(body["claim"]["lease_token"].is_string());
    assert_eq!(body["evidence"]["articles"], 1);
    assert_eq!(body["evidence"]["sampled_files"], 1);
    assert!(body.get("manifest").is_none());
    harness.finish().await
}

#[tokio::test]
async fn a_primary_error_is_a_retry_and_a_deferral_goes_back_to_waiting() -> Result<()> {
    let Some(harness) = Harness::start().await? else {
        return Ok(());
    };
    let server = MockServer::start().await;
    Mock::given(method("POST"))
        .respond_with(
            ResponseTemplate::new(409).set_body_json(json!({"error": "Stale verification result"})),
        )
        .up_to_n_times(1)
        .mount(&server)
        .await;
    remote_run(&harness, &server).await?;
    let (status, reason, leased) = candidate_state(&harness).await?;
    assert_eq!(status, "unknown");
    assert!(reason.unwrap().contains("Remote publication failed: Stale"));
    assert_eq!(leased, None);
    harness.finish().await?;

    let Some(harness) = Harness::start().await? else {
        return Ok(());
    };
    let server = MockServer::start().await;
    Mock::given(method("POST"))
        .respond_with(
            ResponseTemplate::new(409).set_body_json(json!({"deferred": "too few files"})),
        )
        .mount(&server)
        .await;
    remote_run(&harness, &server).await?;
    let (status, reason, _) = candidate_state(&harness).await?;
    assert_eq!(status, "waiting_upload", "{reason:?}");
    harness.finish().await
}

#[tokio::test]
async fn a_lost_response_after_commit_still_counts_as_published() -> Result<()> {
    let Some(harness) = Harness::start().await? else {
        return Ok(());
    };
    // Answers only after committing for the worker, then fails as if the response was lost.
    let server = MockServer::start().await;
    let db = harness.db();
    Mock::given(method("POST"))
        .respond_with(move |request: &wiremock::Request| {
            let body: Value = serde_json::from_slice(&request.body).unwrap();
            let id = body["claim"]["id"].as_i64().unwrap();
            let db = db.clone();
            std::thread::spawn(move || {
                tokio::runtime::Runtime::new().unwrap().block_on(async {
                    sqlx::query(
                        "UPDATE release_verification_candidates SET release_id = 55, status = 'sampled', \
                         lease_token = NULL WHERE id = ?",
                    )
                    .bind(id)
                    .execute(&db)
                    .await
                    .unwrap();
                });
            })
            .join()
            .unwrap();
            ResponseTemplate::new(502)
        })
        .mount(&server)
        .await;
    remote_run(&harness, &server).await?;
    let (status, reason, _) = candidate_state(&harness).await?;
    assert_eq!(status, "sampled", "{reason:?}");
    assert!(
        harness
            .verify
            .config
            .storage_path
            .join("status.json")
            .exists()
    );
    harness.finish().await
}
