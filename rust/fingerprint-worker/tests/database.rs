use flate2::{Compression, write::GzEncoder};
use nntmux_fingerprint_worker::{
    collect,
    connections::{Connections, Contention},
    database::{self, Claim},
    nntp::Provider,
};
use serde_json::json;
use sha2::{Digest, Sha256};
use sqlx::{MySqlPool, Row, mysql::MySqlPoolOptions};
use std::{io::Write, sync::atomic::Ordering, time::Duration};
use tokio::{
    io::{AsyncBufReadExt, AsyncWriteExt, BufReader},
    net::TcpListener,
};

const ID: u64 = 9_900_001;
const GUID: &str = "00000000000000000000000000990001";
fn source(xml: &str) -> (Vec<u8>, String) {
    let mut e = GzEncoder::new(vec![], Compression::default());
    e.write_all(xml.as_bytes()).unwrap();
    let mut sha = Sha256::new();
    sha.update(b"nntmux:nzb-archive-naming:1\0");
    sha.update(xml.as_bytes());
    (e.finish().unwrap(), hex::encode(sha.finalize()))
}
const XML: &str = r#"<nzb><file poster="fixture" date="1" subject='"x.rar"'><groups><group>a.b</group></groups><segments><segment bytes="3" number="1">x@y</segment></segments></file></nzb>"#;
async fn setup(pool: &MySqlPool) {
    cleanup(pool).await;
    sqlx::query("INSERT INTO releases(id,guid) VALUES(?,?)")
        .bind(ID)
        .bind(GUID)
        .execute(pool)
        .await
        .unwrap();
    let (body, digest) = source(XML);
    sqlx::query("INSERT INTO release_nzb_contents(guid,releases_id,body,source_digest,generation,decoded_bytes,created_at,updated_at) VALUES(?,?,?,?,?, ?,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6))").bind(GUID).bind(ID).bind(body).bind(&digest).bind(database::token()).bind(XML.len()as u64).execute(pool).await.unwrap();
    sqlx::query("INSERT INTO release_fingerprint_jobs(release_id,source_digest,status,available_at,created_at,updated_at) VALUES(?,?,'pending','2000-01-01',UTC_TIMESTAMP(6),UTC_TIMESTAMP(6))").bind(ID).bind(digest).execute(pool).await.unwrap();
}
async fn cleanup(pool: &MySqlPool) {
    sqlx::query("DELETE FROM release_fingerprint_metadata WHERE releases_id=?")
        .bind(ID)
        .execute(pool)
        .await
        .unwrap();
    sqlx::query("DELETE FROM releases WHERE id=?")
        .bind(ID)
        .execute(pool)
        .await
        .unwrap();
    sqlx::query("DELETE FROM release_fingerprint_connections WHERE provider='integration-test'")
        .execute(pool)
        .await
        .unwrap();
}
async fn get_claim(pool: &MySqlPool, worker: &str) -> Claim {
    let c = database::claim(pool, worker).await.unwrap().unwrap();
    assert_eq!(c.release_id, ID);
    c
}
#[tokio::test]
async fn real_mariadb_claims_fencing_global_slots_and_binary() {
    let Ok(url) = std::env::var("FINGERPRINT_TEST_DATABASE_URL") else {
        eprintln!(
            "Set FINGERPRINT_TEST_DATABASE_URL to a disposable DB initialized by the Laravel migration to run integration tests"
        );
        return;
    };
    // Only the caller-provided test DB is touched; no application configuration or production lookup.
    let pool = MySqlPoolOptions::new()
        .max_connections(8)
        .connect(&url)
        .await
        .unwrap();
    setup(&pool).await;
    let (a, b) = tokio::join!(
        database::claim(&pool, "laptop-a"),
        database::claim(&pool, "laptop-b")
    );
    let a = a.unwrap();
    let b = b.unwrap();
    assert_ne!(a.is_some(), b.is_some());
    let old = a.or(b).unwrap();
    assert_eq!(database::decode_nzb(&old).unwrap(), XML);
    assert!(database::renew(&pool, &old).await.unwrap());
    sqlx::query("UPDATE release_fingerprint_jobs SET lease_until=TIMESTAMPADD(SECOND,-1,UTC_TIMESTAMP(6)) WHERE release_id=?").bind(ID).execute(&pool).await.unwrap();
    assert!(!database::renew(&pool, &old).await.unwrap());
    let takeover = get_claim(&pool, "laptop-c").await;
    assert_ne!(old.token, takeover.token);
    assert!(
        !database::finish(&pool, &old, "complete", &json!({"old":true}))
            .await
            .unwrap()
    );
    assert!(
        database::finish(&pool, &takeover, "complete", &json!({"new":true}))
            .await
            .unwrap()
    );
    let r: serde_json::Value =
        sqlx::query_scalar("SELECT metadata FROM release_fingerprint_metadata WHERE releases_id=?")
            .bind(ID)
            .fetch_one(&pool)
            .await
            .unwrap();
    assert_eq!(r, json!({"new":true}));
    setup(&pool).await;
    let stale = get_claim(&pool, "laptop-a").await;
    let (body, digest) = source(&XML.replace("poster=\"fixture\"", "poster=\"replacement\""));
    sqlx::query("UPDATE release_nzb_contents SET body=?,source_digest=? WHERE guid=?")
        .bind(body)
        .bind(&digest)
        .bind(GUID)
        .execute(&pool)
        .await
        .unwrap();
    sqlx::query("UPDATE release_fingerprint_jobs SET source_digest=?,status='pending',lease_token=NULL,lease_until=NULL WHERE release_id=?").bind(&digest).bind(ID).execute(&pool).await.unwrap();
    assert!(
        !database::finish(&pool, &stale, "complete", &json!({"stale":true}))
            .await
            .unwrap()
    );
    let current = get_claim(&pool, "laptop-b").await;
    assert_eq!(current.source_digest, digest);
    sqlx::query(
        "INSERT INTO release_fingerprint_connections(provider,slot) VALUES('integration-test',1)",
    )
    .execute(&pool)
    .await
    .unwrap();
    let provider = Provider {
        account: "integration-test".into(),
        host: "127.0.0.1".into(),
        port: 1,
        tls: false,
        username: String::new(),
        password: String::new(),
    };
    let contention = std::sync::Arc::new(Contention::default());
    let mut holder = Connections::new("a".into(), Duration::from_secs(20), contention.clone());
    let held = holder.checkout(&pool, &provider).await.unwrap();
    let held_slot: u32 = sqlx::query_scalar(
        "SELECT slot FROM release_fingerprint_connections WHERE provider='integration-test' AND lease_token=?",
    )
    .bind(&held.token)
    .fetch_one(&pool)
    .await
    .unwrap();
    assert_eq!(held_slot, 1);
    let mut competing = Connections::new("b".into(), Duration::from_secs(20), contention);
    let p = pool.clone();
    let provider_b = provider.clone();
    let waiting = tokio::spawn(async move {
        let checkout = competing.checkout(&p, &provider_b).await;
        (competing, checkout)
    });
    tokio::time::sleep(Duration::from_millis(350)).await;
    assert!(!waiting.is_finished());
    drop(held);
    holder.settle(&pool).await.unwrap();
    let (mut competing, checkout) = tokio::time::timeout(Duration::from_secs(2), waiting)
        .await
        .unwrap()
        .unwrap();
    let taken_slot: u32 = sqlx::query_scalar(
        "SELECT slot FROM release_fingerprint_connections WHERE provider='integration-test' AND lease_token=?",
    )
    .bind(&checkout.unwrap().token)
    .fetch_one(&pool)
    .await
    .unwrap();
    assert_eq!(taken_slot, 1);
    competing.close(&pool).await.unwrap();
    // A lost shared connection lease also fences its worker, even with a live job lease.
    assert!(database::renew(&pool, &current).await.unwrap());
    let held = holder.checkout(&pool, &provider).await.unwrap();
    assert!(holder.leases().renew(&pool).await.unwrap());
    sqlx::query("UPDATE release_fingerprint_connections SET lease_until=TIMESTAMPADD(SECOND,-1,UTC_TIMESTAMP(6)) WHERE provider='integration-test'").execute(&pool).await.unwrap();
    assert!(!holder.leases().renew(&pool).await.unwrap());
    drop(held);
    holder.close(&pool).await.unwrap();
    setup(&pool).await;
    let listener = TcpListener::bind("127.0.0.1:0").await.unwrap();
    let port = listener.local_addr().unwrap().port();
    let server = tokio::spawn(async move {
        let (stream, _) = listener.accept().await.unwrap();
        let mut io = BufReader::new(stream);
        io.write_all(b"200 fixture\r\n").await.unwrap();
        loop {
            let mut command = String::new();
            if io.read_line(&mut command).await.unwrap() == 0 {
                break;
            }
            match command.trim() {
                "CAPABILITIES" => io
                    .write_all(b"101 capabilities\r\nIMPLEMENTATION Fixture\r\n.\r\n")
                    .await
                    .unwrap(),
                "ARTICLE <x@y>" => {
                    let payload = b"abc";
                    let encoded: Vec<u8> = payload.iter().map(|b| b.wrapping_add(42)).collect();
                    let mut response=b"220 0 <x@y> follows\r\nMessage-ID: <x@y>\r\n\r\n=ybegin line=128 size=3 name=x.rar\r\n".to_vec();
                    response.extend(encoded);
                    response.extend(
                        format!(
                            "\r\n=yend size=3 crc32={:08x}\r\n.\r\n",
                            crc32fast::hash(payload)
                        )
                        .as_bytes(),
                    );
                    io.write_all(&response).await.unwrap();
                }
                _ => panic!("unexpected fixture command"),
            }
        }
    });
    let binary = env!("CARGO_BIN_EXE_nntmux-fingerprint-worker");
    let output = tokio::process::Command::new(binary)
        .args([
            "--once",
            "--nntp-server",
            "127.0.0.1",
            "--nntp-port",
            &port.to_string(),
            "--nntp-tls",
            "false",
            "--alternate-percent",
            "0",
        ])
        .env("DATABASE_URL", &url)
        .env_remove("NNTP_SERVER_A")
        .env_remove("NNTP_USERNAME")
        .env_remove("NNTP_PASSWORD")
        .output()
        .await
        .unwrap();
    assert!(
        output.status.success(),
        "{}",
        String::from_utf8_lossy(&output.stderr)
    );
    server.await.unwrap();
    let row =
        sqlx::query("SELECT status,metadata FROM release_fingerprint_metadata WHERE releases_id=?")
            .bind(ID)
            .fetch_one(&pool)
            .await
            .unwrap();
    assert_eq!(row.get::<String, _>("status"), "complete");
    let data: serde_json::Value = row.get("metadata");
    assert_eq!(data["collector"]["engine"], "rust");
    assert_eq!(data["articles"][0]["yenc"]["crc_ok"], true);
    let status: String =
        sqlx::query_scalar("SELECT status FROM release_fingerprint_jobs WHERE release_id=?")
            .bind(ID)
            .fetch_one(&pool)
            .await
            .unwrap();
    assert_eq!(status, "complete");
    let slots: i64 = sqlx::query_scalar(
        "SELECT COUNT(*) FROM release_fingerprint_connections WHERE lease_token IS NOT NULL",
    )
    .fetch_one(&pool)
    .await
    .unwrap();
    assert_eq!(slots, 0);
    assert_eq!(
        collect::analyze(
            &nntmux_fingerprint_worker::manifest::parse(XML).unwrap(),
            &[]
        )
        .status,
        "partial"
    );
    // Shared alternate cooldown and one fenced recovery probe across clients.
    nntmux_fingerprint_worker::breaker::unavailable(&pool, "alternate")
        .await
        .unwrap();
    let blocked = nntmux_fingerprint_worker::breaker::begin(&pool, "alternate")
        .await
        .unwrap();
    assert_eq!(
        blocked.skipped_reason.as_deref(),
        Some("recent_provider_failure")
    );
    sqlx::query("UPDATE release_fingerprint_provider_state SET blocked_until=TIMESTAMPADD(SECOND,-1,UTC_TIMESTAMP(6)) WHERE provider='alternate'").execute(&pool).await.unwrap();
    let old_probe = nntmux_fingerprint_worker::breaker::begin(&pool, "alternate")
        .await
        .unwrap();
    assert!(old_probe.skipped_reason.is_none());
    let competing_probe = nntmux_fingerprint_worker::breaker::begin(&pool, "alternate")
        .await
        .unwrap();
    assert_eq!(
        competing_probe.skipped_reason.as_deref(),
        Some("provider_probe_in_progress")
    );
    nntmux_fingerprint_worker::breaker::unavailable(&pool, "alternate")
        .await
        .unwrap();
    nntmux_fingerprint_worker::breaker::reachable(&pool, "alternate", &old_probe)
        .await
        .unwrap();
    assert_eq!(
        nntmux_fingerprint_worker::breaker::begin(&pool, "alternate")
            .await
            .unwrap()
            .skipped_reason
            .as_deref(),
        Some("recent_provider_failure")
    );
    sqlx::query("UPDATE release_fingerprint_provider_state SET blocked_until=TIMESTAMPADD(SECOND,-1,UTC_TIMESTAMP(6)) WHERE provider='alternate'").execute(&pool).await.unwrap();
    let current_probe = nntmux_fingerprint_worker::breaker::begin(&pool, "alternate")
        .await
        .unwrap();
    nntmux_fingerprint_worker::breaker::reachable(&pool, "alternate", &current_probe)
        .await
        .unwrap();
    let generation: Option<String> = sqlx::query_scalar(
        "SELECT generation FROM release_fingerprint_provider_state WHERE provider='alternate'",
    )
    .fetch_one(&pool)
    .await
    .unwrap();
    assert!(generation.is_none());
    three_process_clients(&pool, &url).await;
    #[cfg(unix)]
    for signal in ["-TERM", "-INT"] {
        graceful_signal(&pool, &url, signal).await;
    }
    cleanup(&pool).await;
    pool.close().await;
}

async fn three_process_clients(pool: &MySqlPool, url: &str) {
    setup(pool).await;
    for offset in 1..6u64 {
        let id = ID + offset;
        let guid = format!("{id:032x}");
        sqlx::query("DELETE FROM release_fingerprint_metadata WHERE releases_id=?")
            .bind(id)
            .execute(pool)
            .await
            .unwrap();
        sqlx::query("DELETE FROM releases WHERE id=?")
            .bind(id)
            .execute(pool)
            .await
            .unwrap();
        sqlx::query("INSERT INTO releases(id,guid) VALUES(?,?)")
            .bind(id)
            .bind(&guid)
            .execute(pool)
            .await
            .unwrap();
        sqlx::query("INSERT INTO release_nzb_contents(guid,releases_id,body,source_digest,generation,decoded_bytes,created_at,updated_at) SELECT ?,?,body,source_digest,?,decoded_bytes,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6) FROM release_nzb_contents WHERE guid=?").bind(&guid).bind(id).bind(database::token()).bind(GUID).execute(pool).await.unwrap();
        sqlx::query("INSERT INTO release_fingerprint_jobs(release_id,source_digest,status,available_at,created_at,updated_at) SELECT ?,source_digest,'pending','2000-01-01',UTC_TIMESTAMP(6),UTC_TIMESTAMP(6) FROM release_nzb_contents WHERE guid=?").bind(id).bind(&guid).execute(pool).await.unwrap();
    }
    let slots: Vec<u32> = sqlx::query_scalar(
        "SELECT slot FROM release_fingerprint_connections WHERE provider='primary' ORDER BY slot",
    )
    .fetch_all(pool)
    .await
    .unwrap();
    sqlx::query("DELETE FROM release_fingerprint_connections WHERE provider='primary' AND slot>2")
        .execute(pool)
        .await
        .unwrap();
    let listener = TcpListener::bind("127.0.0.1:0").await.unwrap();
    let port = listener.local_addr().unwrap().port();
    let active = std::sync::Arc::new(std::sync::atomic::AtomicU32::new(0));
    let maximum = std::sync::Arc::new(std::sync::atomic::AtomicU32::new(0));
    let server_active = active.clone();
    let server_maximum = maximum.clone();
    let server = tokio::spawn(async move {
        let mut connections = tokio::task::JoinSet::new();
        for _ in 0..6 {
            let (stream, _) = listener.accept().await.unwrap();
            let active = server_active.clone();
            let maximum = server_maximum.clone();
            connections.spawn(async move{
            let now=active.fetch_add(1,Ordering::SeqCst)+1;maximum.fetch_max(now,Ordering::SeqCst);
            let mut io=BufReader::new(stream);io.write_all(b"200 fixture\r\n").await.unwrap();
            loop{let mut command=String::new();if io.read_line(&mut command).await.unwrap()==0{break;}
                match command.trim(){
                    "CAPABILITIES"=>io.write_all(b"101 capabilities\r\nIMPLEMENTATION Fixture\r\n.\r\n").await.unwrap(),
                    "ARTICLE <x@y>"=>{
                        tokio::time::sleep(Duration::from_millis(100)).await;
                        let payload=b"abc";let encoded:Vec<u8>=payload.iter().map(|b|b.wrapping_add(42)).collect();
                        let mut response=b"220 0 <x@y> follows\r\nMessage-ID: <x@y>\r\n\r\n=ybegin line=128 size=3 name=x.rar\r\n".to_vec();response.extend(encoded);response.extend(format!("\r\n=yend size=3 crc32={:08x}\r\n.\r\n",crc32fast::hash(payload)).as_bytes());io.write_all(&response).await.unwrap();
                    },_=>panic!("unexpected fixture command")
                }
            }
            active.fetch_sub(1,Ordering::SeqCst);
        });
        }
        while let Some(result) = connections.join_next().await {
            result.unwrap();
        }
    });
    let mut children = vec![];
    for worker in ["laptop-a", "laptop-b", "laptop-c"] {
        let child = tokio::process::Command::new(env!("CARGO_BIN_EXE_nntmux-fingerprint-worker"))
            .args([
                "--max-jobs",
                "2",
                "--concurrency",
                "2",
                "--worker-id",
                worker,
                "--nntp-server",
                "127.0.0.1",
                "--nntp-port",
                &port.to_string(),
                "--nntp-tls",
                "false",
                "--alternate-percent",
                "0",
            ])
            .env("DATABASE_URL", url)
            .env_remove("NNTP_SERVER_A")
            .env_remove("NNTP_USERNAME")
            .env_remove("NNTP_PASSWORD")
            .stdout(std::process::Stdio::piped())
            .stderr(std::process::Stdio::piped())
            .spawn()
            .unwrap();
        children.push(child);
    }
    let mut ids = std::collections::BTreeSet::new();
    for child in children {
        let output = tokio::time::timeout(Duration::from_secs(20), child.wait_with_output())
            .await
            .unwrap()
            .unwrap();
        assert!(
            output.status.success(),
            "{}",
            String::from_utf8_lossy(&output.stderr)
        );
        let events: Vec<serde_json::Value> = String::from_utf8(output.stdout)
            .unwrap()
            .lines()
            .map(|s| serde_json::from_str(s).unwrap())
            .collect();
        assert_eq!(events.len(), 2);
        for event in events {
            assert_eq!(event["event"], "completed");
            assert_eq!(event["status"], "complete");
            assert!(ids.insert(event["release_id"].as_u64().unwrap()));
        }
    }
    tokio::time::timeout(Duration::from_secs(5), server)
        .await
        .unwrap()
        .unwrap();
    assert_eq!(ids.len(), 6);
    assert!(maximum.load(Ordering::SeqCst) <= 2);
    assert_eq!(active.load(Ordering::SeqCst), 0);
    let complete:i64=sqlx::query_scalar("SELECT COUNT(*) FROM release_fingerprint_jobs WHERE release_id>=? AND release_id<? AND status='complete'").bind(ID).bind(ID+6).fetch_one(pool).await.unwrap();
    assert_eq!(complete, 6);
    let results:i64=sqlx::query_scalar("SELECT COUNT(*) FROM release_fingerprint_metadata WHERE releases_id>=? AND releases_id<? AND status='complete'").bind(ID).bind(ID+6).fetch_one(pool).await.unwrap();
    assert_eq!(results, 6);
    for slot in slots {
        sqlx::query(
            "INSERT IGNORE INTO release_fingerprint_connections(provider,slot) VALUES('primary',?)",
        )
        .bind(slot)
        .execute(pool)
        .await
        .unwrap();
    }
    for id in ID..ID + 6 {
        sqlx::query("DELETE FROM release_fingerprint_metadata WHERE releases_id=?")
            .bind(id)
            .execute(pool)
            .await
            .unwrap();
        sqlx::query("DELETE FROM releases WHERE id=?")
            .bind(id)
            .execute(pool)
            .await
            .unwrap();
    }
    println!(
        "Three independent Rust clients completed six unique releases; peak NNTP connections={} with shared budget=2",
        maximum.load(Ordering::SeqCst)
    );
}

#[cfg(unix)]
async fn graceful_signal(pool: &MySqlPool, url: &str, signal: &str) {
    setup(pool).await;
    let id = ID + 1;
    let guid = format!("{id:032x}");
    sqlx::query("DELETE FROM release_fingerprint_metadata WHERE releases_id=?")
        .bind(id)
        .execute(pool)
        .await
        .unwrap();
    sqlx::query("DELETE FROM releases WHERE id=?")
        .bind(id)
        .execute(pool)
        .await
        .unwrap();
    sqlx::query("INSERT INTO releases(id,guid) VALUES(?,?)")
        .bind(id)
        .bind(&guid)
        .execute(pool)
        .await
        .unwrap();
    sqlx::query("INSERT INTO release_nzb_contents(guid,releases_id,body,source_digest,generation,decoded_bytes,created_at,updated_at) SELECT ?,?,body,source_digest,?,decoded_bytes,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6) FROM release_nzb_contents WHERE guid=?").bind(&guid).bind(id).bind(database::token()).bind(GUID).execute(pool).await.unwrap();
    sqlx::query("INSERT INTO release_fingerprint_jobs(release_id,source_digest,status,available_at,created_at,updated_at) SELECT ?,source_digest,'pending','2000-01-01',UTC_TIMESTAMP(6),UTC_TIMESTAMP(6) FROM release_nzb_contents WHERE guid=?").bind(id).bind(&guid).execute(pool).await.unwrap();
    let listener = TcpListener::bind("127.0.0.1:0").await.unwrap();
    let port = listener.local_addr().unwrap().port();
    let (started_tx, started_rx) = tokio::sync::oneshot::channel();
    let server = tokio::spawn(async move {
        let (stream, _) = listener.accept().await.unwrap();
        let mut io = BufReader::new(stream);
        io.write_all(b"200 fixture\r\n").await.unwrap();
        let mut started = Some(started_tx);
        loop {
            let mut command = String::new();
            if io.read_line(&mut command).await.unwrap() == 0 {
                break;
            }
            match command.trim() {
                "CAPABILITIES" => io
                    .write_all(b"101 capabilities\r\nIMPLEMENTATION Fixture\r\n.\r\n")
                    .await
                    .unwrap(),
                "ARTICLE <x@y>" => {
                    if let Some(started) = started.take() {
                        let _ = started.send(());
                    }
                    tokio::time::sleep(Duration::from_secs(2)).await;
                    let payload = b"abc";
                    let encoded: Vec<u8> = payload.iter().map(|b| b.wrapping_add(42)).collect();
                    let mut response=b"220 0 <x@y> follows\r\nMessage-ID: <x@y>\r\n\r\n=ybegin line=128 size=3 name=x.rar\r\n".to_vec();
                    response.extend(encoded);
                    response.extend(
                        format!(
                            "\r\n=yend size=3 crc32={:08x}\r\n.\r\n",
                            crc32fast::hash(payload)
                        )
                        .as_bytes(),
                    );
                    io.write_all(&response).await.unwrap();
                }
                _ => panic!("unexpected fixture command"),
            }
        }
    });
    let mut child = tokio::process::Command::new(env!("CARGO_BIN_EXE_nntmux-fingerprint-worker"))
        .args([
            "--concurrency",
            "1",
            "--worker-id",
            "signal-test",
            "--nntp-server",
            "127.0.0.1",
            "--nntp-port",
            &port.to_string(),
            "--nntp-tls",
            "false",
            "--alternate-percent",
            "0",
        ])
        .env("DATABASE_URL", url)
        .env_remove("NNTP_SERVER_A")
        .env_remove("NNTP_USERNAME")
        .env_remove("NNTP_PASSWORD")
        .stdout(std::process::Stdio::piped())
        .stderr(std::process::Stdio::piped())
        .spawn()
        .unwrap();
    tokio::time::timeout(Duration::from_secs(5), started_rx)
        .await
        .unwrap()
        .unwrap();
    let pid = child.id().unwrap();
    assert!(
        tokio::process::Command::new("/bin/kill")
            .args([signal, &pid.to_string()])
            .status()
            .await
            .unwrap()
            .success()
    );
    tokio::time::sleep(Duration::from_millis(150)).await;
    assert!(
        child.try_wait().unwrap().is_none(),
        "worker killed its in-flight job instead of draining"
    );
    let output = tokio::time::timeout(Duration::from_secs(10), child.wait_with_output())
        .await
        .unwrap()
        .unwrap();
    assert!(
        output.status.success(),
        "{}",
        String::from_utf8_lossy(&output.stderr)
    );
    assert!(String::from_utf8_lossy(&output.stderr).contains("draining"));
    server.await.unwrap();
    let status: String =
        sqlx::query_scalar("SELECT status FROM release_fingerprint_jobs WHERE release_id=?")
            .bind(ID)
            .fetch_one(pool)
            .await
            .unwrap();
    assert_eq!(status, "complete");
    let status: String =
        sqlx::query_scalar("SELECT status FROM release_fingerprint_jobs WHERE release_id=?")
            .bind(id)
            .fetch_one(pool)
            .await
            .unwrap();
    assert_eq!(status, "pending");
    let attempts: u32 =
        sqlx::query_scalar("SELECT attempts FROM release_fingerprint_jobs WHERE release_id=?")
            .bind(id)
            .fetch_one(pool)
            .await
            .unwrap();
    assert_eq!(attempts, 0);
    sqlx::query("DELETE FROM release_fingerprint_metadata WHERE releases_id=?")
        .bind(id)
        .execute(pool)
        .await
        .unwrap();
    sqlx::query("DELETE FROM releases WHERE id=?")
        .bind(id)
        .execute(pool)
        .await
        .unwrap();
    cleanup(pool).await;
    println!("{signal} drained the active release and left the next release unclaimed");
}
