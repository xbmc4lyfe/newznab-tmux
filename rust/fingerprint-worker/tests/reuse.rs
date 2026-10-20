//! Provider connection reuse across releases.
//!
//! The first group runs without a database. The rest need `FINGERPRINT_TEST_DATABASE_URL`, a
//! **disposable** migrated MariaDB database, exactly like `tests/database.rs`; they replace the
//! shared `primary` budget inventory for their duration (restored afterwards) and use release
//! IDs from 9,910,001.
mod support;

use flate2::{Compression, write::GzEncoder};
use nntmux_fingerprint_worker::{
    breaker,
    collect::{self, Collected},
    connections::{Connections, Contention},
    database::{self, Claim},
    nntp::{Client, Provider},
};
use sha2::{Digest, Sha256};
use sqlx::{MySqlPool, mysql::MySqlPoolOptions};
use std::{
    io::Write,
    sync::{
        Arc,
        atomic::{AtomicBool, Ordering},
    },
    time::Duration,
};
use support::{Script, ScriptedNntp};

const FIRST_ID: u64 = 9_910_001;
const IDLE: Duration = Duration::from_secs(20);

fn provider(account: &str, port: u16) -> Provider {
    Provider {
        account: account.into(),
        host: "127.0.0.1".into(),
        port,
        tls: false,
        username: "user".into(),
        password: "secret".into(),
    }
}

async fn eventually(what: &str, mut condition: impl AsyncFnMut() -> bool) {
    for _ in 0..200 {
        if condition().await {
            return;
        }
        tokio::time::sleep(Duration::from_millis(10)).await;
    }
    panic!("timed out waiting for {what}");
}

// ---- no database ----

#[tokio::test]
async fn idle_connection_is_ready_only_while_the_stream_is_in_step() {
    let server = ScriptedNntp::start(Script::default()).await;
    let p = provider("primary", server.port);

    let mut unprofiled = Client::connect(&p, 2).await.unwrap();
    assert!(
        !unprofiled.ready_for_reuse(false).await,
        "a connection that was never profiled cannot be reused"
    );

    let mut client = Client::connect(&p, 2).await.unwrap();
    client.profile().await.unwrap();
    assert!(client.profiled());
    assert!(client.ready_for_reuse(false).await);
    assert_eq!(server.stats.dates(), 0, "no ping unless requested");
    assert!(client.ready_for_reuse(true).await);
    assert_eq!(server.stats.dates(), 1);
    assert!(!client.poisoned());

    server.kill_connections();
    let mut closed = false;
    for _ in 0..100 {
        if !client.ready_for_reuse(false).await {
            closed = true;
            break;
        }
        tokio::time::sleep(Duration::from_millis(10)).await;
    }
    assert!(closed, "a server-closed connection must not be reused");
    assert!(client.poisoned());
    assert!(client.article("x@y").await.is_err());
}

#[tokio::test]
async fn unsolicited_bytes_and_silent_drops_poison_an_idle_connection() {
    // Provider idle timeout: the server speaks first, then closes.
    let server = ScriptedNntp::start(Script {
        idle_close: Some(Duration::from_millis(50)),
        ..Script::default()
    })
    .await;
    let p = provider("primary", server.port);
    let mut client = Client::connect(&p, 2).await.unwrap();
    client.profile().await.unwrap();
    tokio::time::sleep(Duration::from_millis(250)).await;
    assert!(!client.ready_for_reuse(false).await);
    assert!(client.poisoned());

    // A silently dropped path only shows on the wire when a probe is sent.
    let server = ScriptedNntp::start(Script::default()).await;
    let p = provider("primary", server.port);
    let mut client = Client::connect(&p, 1).await.unwrap();
    client.profile().await.unwrap();
    server.kill_connections();
    tokio::time::sleep(Duration::from_millis(100)).await;
    assert!(!client.ready_for_reuse(true).await);
}

// ---- database ----

static DATABASE: tokio::sync::Mutex<()> = tokio::sync::Mutex::const_new(());

struct Fixture {
    pool: MySqlPool,
    original_primary: Vec<u32>,
    _guard: tokio::sync::MutexGuard<'static, ()>,
}

impl Fixture {
    async fn start(budget: u32) -> Option<Self> {
        let Ok(url) = std::env::var("FINGERPRINT_TEST_DATABASE_URL") else {
            eprintln!(
                "Set FINGERPRINT_TEST_DATABASE_URL to a disposable DB initialized by the Laravel migration to run connection reuse tests"
            );
            return None;
        };
        let guard = DATABASE.lock().await;
        let pool = MySqlPoolOptions::new()
            .max_connections(16)
            .connect(&url)
            .await
            .unwrap();
        let original_primary = sqlx::query_scalar(
            "SELECT slot FROM release_fingerprint_connections WHERE provider='primary' ORDER BY slot",
        )
        .fetch_all(&pool)
        .await
        .unwrap();
        let fixture = Self {
            pool,
            original_primary,
            _guard: guard,
        };
        fixture.reset(budget).await;
        Some(fixture)
    }
    async fn reset(&self, budget: u32) {
        sqlx::query("DELETE FROM releases WHERE id>=?")
            .bind(FIRST_ID)
            .execute(&self.pool)
            .await
            .unwrap();
        sqlx::query("DELETE FROM release_fingerprint_connections WHERE provider='primary'")
            .execute(&self.pool)
            .await
            .unwrap();
        for slot in 1..=budget {
            sqlx::query(
                "INSERT INTO release_fingerprint_connections(provider,slot) VALUES('primary',?)",
            )
            .bind(slot)
            .execute(&self.pool)
            .await
            .unwrap();
        }
        // The alternate inventory is left in place; make sure none of it is leased.
        sqlx::query("UPDATE release_fingerprint_connections SET lease_token=NULL,lease_until=NULL,worker_id=NULL WHERE provider='alternate'").execute(&self.pool).await.unwrap();
        sqlx::query("UPDATE release_fingerprint_provider_state SET generation=NULL,blocked_until=NULL,probe_token=NULL,probe_until=NULL WHERE provider='alternate'").execute(&self.pool).await.unwrap();
    }
    async fn finish(self) {
        self.reset(0).await;
        for slot in &self.original_primary {
            sqlx::query(
                "INSERT INTO release_fingerprint_connections(provider,slot) VALUES('primary',?)",
            )
            .bind(slot)
            .execute(&self.pool)
            .await
            .unwrap();
        }
        sqlx::query("UPDATE release_fingerprint_provider_state SET generation=NULL,blocked_until=NULL,probe_token=NULL,probe_until=NULL WHERE provider='alternate'").execute(&self.pool).await.unwrap();
        self.pool.close().await;
    }
    async fn leased(&self, provider: &str) -> i64 {
        sqlx::query_scalar(
            "SELECT COUNT(*) FROM release_fingerprint_connections WHERE provider=? AND lease_token IS NOT NULL",
        )
        .bind(provider)
        .fetch_one(&self.pool)
        .await
        .unwrap()
    }
    async fn seed(&self, id: u64, samples: u64) {
        let mut xml = String::from("<nzb>");
        for file in 0..samples {
            xml.push_str(&format!(
                "<file poster=\"t\" date=\"1\" subject='\"f{file}.rar\"'><groups><group>a.b</group></groups><segments><segment bytes=\"2048\" number=\"1\">r{id}f{file}@t</segment></segments></file>"
            ));
        }
        xml.push_str("</nzb>");
        let mut gz = GzEncoder::new(vec![], Compression::fast());
        gz.write_all(xml.as_bytes()).unwrap();
        let mut sha = Sha256::new();
        sha.update(b"nntmux:nzb-archive-naming:1\0");
        sha.update(xml.as_bytes());
        let digest = hex::encode(sha.finalize());
        let guid = format!("{id:032x}");
        sqlx::query("INSERT INTO releases(id,guid) VALUES(?,?)")
            .bind(id)
            .bind(&guid)
            .execute(&self.pool)
            .await
            .unwrap();
        sqlx::query("INSERT INTO release_nzb_contents(guid,releases_id,body,source_digest,generation,decoded_bytes,created_at,updated_at) VALUES(?,?,?,?,?,?,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6))")
            .bind(&guid)
            .bind(id)
            .bind(gz.finish().unwrap())
            .bind(&digest)
            .bind(database::token())
            .bind(xml.len() as u64)
            .execute(&self.pool)
            .await
            .unwrap();
        sqlx::query("INSERT INTO release_fingerprint_jobs(release_id,source_digest,status,available_at,created_at,updated_at) VALUES(?,?,'pending','2000-01-01',UTC_TIMESTAMP(6),UTC_TIMESTAMP(6))")
            .bind(id)
            .bind(&digest)
            .execute(&self.pool)
            .await
            .unwrap();
    }
    async fn claim(&self) -> Option<Claim> {
        database::claim(&self.pool, "reuse-test").await.unwrap()
    }
    /// One release exactly as the worker processes it: claim, collect, finish, settle.
    async fn process(
        &self,
        conns: &mut Connections,
        providers: &[Provider],
        alternate_percent: u32,
    ) -> Collected {
        let claim = self.claim().await.expect("a pending job");
        let collected = collect::run(&self.pool, &claim, providers, alternate_percent, 2, conns)
            .await
            .unwrap();
        assert!(
            database::finish(&self.pool, &claim, &collected.status, &collected.data)
                .await
                .unwrap()
        );
        conns.settle(&self.pool).await.unwrap();
        collected
    }
}

fn connections(contention: &Arc<Contention>) -> Connections {
    Connections::new("reuse-test".into(), IDLE, contention.clone())
}

#[tokio::test]
async fn a_connection_and_its_budget_row_are_reused_across_releases() {
    let Some(db) = Fixture::start(4).await else {
        return;
    };
    let server = ScriptedNntp::start(Script::default()).await;
    let providers = [provider("primary", server.port)];
    let mut conns = connections(&Arc::default());
    for offset in 0..3 {
        db.seed(FIRST_ID + offset, 3).await;
    }

    let first = db.process(&mut conns, &providers, 0).await;
    assert_eq!(first.status, "complete");
    assert_eq!(first.timings.connections_opened, 1);
    assert_eq!(first.timings.connections_reused, 0);
    assert_eq!(db.leased("primary").await, 1, "the row stays leased");
    assert_eq!(conns.idle_connections(), 1);

    let mut later = vec![];
    for _ in 0..2 {
        let collected = db.process(&mut conns, &providers, 0).await;
        assert_eq!(collected.status, "complete");
        assert_eq!(collected.timings.connections_opened, 0);
        assert_eq!(collected.timings.connections_reused, 1);
        assert_eq!(
            collected.timings.connect_ms + collected.timings.profile_ms,
            0
        );
        later.push(collected);
    }
    // Evidence is the same whether or not the connection was reused.
    for collected in &later {
        assert_eq!(
            collected.data["providers"][0]["profile"],
            first.data["providers"][0]["profile"]
        );
        assert_eq!(
            collected.data["providers"][0]["implementation"],
            first.data["providers"][0]["implementation"]
        );
        assert_eq!(collected.data["providers"][0]["articles"]["found"], 3);
    }
    let stats = &server.stats;
    assert_eq!(stats.accepted(), 1, "one socket served three releases");
    assert_eq!(stats.auth_users.load(Ordering::SeqCst), 1);
    assert_eq!(stats.capabilities(), 1, "profiled once, not per release");
    assert_eq!(stats.articles(), 9);
    assert_eq!(db.leased("primary").await, 1);

    conns.close(&db.pool).await.unwrap();
    assert_eq!(db.leased("primary").await, 0);
    eventually("the socket to close", async || stats.open() == 0).await;
    db.finish().await;
}

#[tokio::test]
async fn a_poisoned_connection_is_never_reused_and_gives_its_row_back() {
    let Some(db) = Fixture::start(4).await else {
        return;
    };
    // The third article of the first release is cut off mid-response.
    let server = ScriptedNntp::start(Script {
        drop_every: 3,
        ..Script::default()
    })
    .await;
    let providers = [provider("primary", server.port)];
    let mut conns = connections(&Arc::default());
    db.seed(FIRST_ID, 3).await;
    db.seed(FIRST_ID + 1, 2).await;

    let first = db.process(&mut conns, &providers, 0).await;
    assert_eq!(first.status, "partial");
    assert_eq!(first.data["providers"][0]["articles"]["errors"], 1);
    assert_eq!(conns.idle_connections(), 0, "poisoned: not retained");
    assert_eq!(db.leased("primary").await, 0, "its row was released");

    let second = db.process(&mut conns, &providers, 0).await;
    assert_eq!(second.status, "complete");
    assert_eq!(second.timings.connections_reused, 0);
    assert_eq!(second.timings.connections_opened, 1);
    assert_eq!(server.stats.accepted(), 2, "a fresh socket after poisoning");
    assert_eq!(server.stats.capabilities(), 2);
    assert_eq!(
        db.leased("primary").await,
        1,
        "the healthy replacement is retained"
    );

    conns.close(&db.pool).await.unwrap();
    assert_eq!(db.leased("primary").await, 0);
    db.finish().await;
}

#[tokio::test]
async fn a_connection_the_provider_closed_is_replaced_without_failing_an_article() {
    let Some(db) = Fixture::start(4).await else {
        return;
    };
    let server = ScriptedNntp::start(Script::default()).await;
    let providers = [provider("primary", server.port)];
    let mut conns = connections(&Arc::default());
    for offset in 0..3 {
        db.seed(FIRST_ID + offset, 3).await;
    }
    assert_eq!(
        db.process(&mut conns, &providers, 0).await.status,
        "complete"
    );

    // Closed and noticed: replaced before the release starts.
    server.kill_connections();
    tokio::time::sleep(Duration::from_millis(150)).await;
    let second = db.process(&mut conns, &providers, 0).await;
    assert_eq!(second.status, "complete");
    assert_eq!(second.timings.connections_reused, 0);
    assert_eq!(second.data["providers"][0]["articles"]["found"], 3);
    assert_eq!(db.leased("primary").await, 1);

    // Closed immediately before use: whether the close is noticed beforehand or on the first
    // command, the release still completes with every article and exactly one budget row.
    server.kill_connections();
    let third = db.process(&mut conns, &providers, 0).await;
    assert_eq!(third.status, "complete");
    assert_eq!(third.data["providers"][0]["articles"]["found"], 3);
    assert_eq!(server.stats.accepted(), 3);
    // A socket re-opened mid-release is unprofiled and therefore not kept.
    assert!(db.leased("primary").await <= 1);

    conns.close(&db.pool).await.unwrap();
    db.finish().await;
}

#[tokio::test]
async fn idle_connections_expire_and_release_their_rows() {
    let Some(db) = Fixture::start(4).await else {
        return;
    };
    let server = ScriptedNntp::start(Script::default()).await;
    let providers = [provider("primary", server.port)];
    let mut conns = Connections::new(
        "reuse-test".into(),
        Duration::from_millis(300),
        Arc::default(),
    );
    db.seed(FIRST_ID, 2).await;
    db.process(&mut conns, &providers, 0).await;
    assert_eq!(db.leased("primary").await, 1);

    conns.expire_idle(&db.pool).await.unwrap();
    assert_eq!(db.leased("primary").await, 1, "not idle long enough yet");
    tokio::time::sleep(Duration::from_millis(400)).await;
    conns.expire_idle(&db.pool).await.unwrap();
    assert_eq!(conns.idle_connections(), 0);
    assert_eq!(db.leased("primary").await, 0);
    eventually("the socket to close", async || server.stats.open() == 0).await;

    // Reuse disabled: a connection per release, nothing kept.
    let mut conns = Connections::new("reuse-test".into(), Duration::ZERO, Arc::default());
    db.seed(FIRST_ID + 1, 2).await;
    db.process(&mut conns, &providers, 0).await;
    assert_eq!(conns.idle_connections(), 0);
    assert_eq!(db.leased("primary").await, 0);
    db.finish().await;
}

#[tokio::test]
async fn a_lost_or_expired_lease_forbids_reuse_and_fails_the_heartbeat() {
    let Some(db) = Fixture::start(4).await else {
        return;
    };
    let server = ScriptedNntp::start(Script::default()).await;
    let providers = [provider("primary", server.port)];
    let mut conns = connections(&Arc::default());
    db.seed(FIRST_ID, 2).await;
    db.seed(FIRST_ID + 1, 2).await;
    db.process(&mut conns, &providers, 0).await;
    assert_eq!(db.leased("primary").await, 1);

    // The lease lapsed while the connection sat idle (for example the laptop slept).
    sqlx::query("UPDATE release_fingerprint_connections SET lease_until=TIMESTAMPADD(SECOND,-1,UTC_TIMESTAMP(6)) WHERE lease_token IS NOT NULL").execute(&db.pool).await.unwrap();
    let second = db.process(&mut conns, &providers, 0).await;
    assert_eq!(
        second.timings.connections_reused, 0,
        "lapsed lease: no reuse"
    );
    assert_eq!(second.status, "complete");
    assert_eq!(server.stats.accepted(), 2);
    assert_eq!(db.leased("primary").await, 1, "one live socket, one row");

    // While a checkout is in flight the heartbeat renews it and notices when it is taken away.
    db.seed(FIRST_ID + 2, 2).await;
    let leases = conns.leases();
    let checkout = conns
        .checkout(&db.pool, &providers[0])
        .await
        .expect("checkout");
    assert!(checkout.reused);
    assert!(leases.renew(&db.pool).await.unwrap());
    sqlx::query("UPDATE release_fingerprint_connections SET lease_token='stolen' WHERE lease_token IS NOT NULL").execute(&db.pool).await.unwrap();
    assert!(
        !leases.renew(&db.pool).await.unwrap(),
        "a lost connection lease fences the job"
    );
    drop(checkout);
    conns.close(&db.pool).await.unwrap();
    db.finish().await;
}

#[tokio::test]
async fn a_cancelled_job_closes_its_socket_and_releases_its_row() {
    let Some(db) = Fixture::start(4).await else {
        return;
    };
    let server = ScriptedNntp::start(Script {
        article_extra: Duration::from_millis(700),
        ..Script::default()
    })
    .await;
    let providers = [provider("primary", server.port)];
    let mut conns = connections(&Arc::default());
    db.seed(FIRST_ID, 3).await;
    let claim = db.claim().await.unwrap();
    // The job exceeds its deadline halfway through an article.
    let outcome = tokio::time::timeout(
        Duration::from_millis(400),
        collect::run(&db.pool, &claim, &providers, 0, 20, &mut conns),
    )
    .await;
    assert!(outcome.is_err(), "the job must have been cancelled");
    assert_eq!(db.leased("primary").await, 1, "not yet settled");
    conns.settle(&db.pool).await.unwrap();
    assert_eq!(db.leased("primary").await, 0);
    assert_eq!(conns.idle_connections(), 0);
    assert_eq!(conns.leases().held().await, 0);
    eventually("the socket to close", async || server.stats.open() == 0).await;
    db.finish().await;
}

#[tokio::test]
async fn draining_releases_every_retained_row() {
    let Some(db) = Fixture::start(6).await else {
        return;
    };
    let server = ScriptedNntp::start(Script::default()).await;
    let providers = [provider("primary", server.port)];
    let contention = Arc::new(Contention::default());
    let mut tasks = vec![connections(&contention), connections(&contention)];
    for offset in 0..2 {
        db.seed(FIRST_ID + offset, 2).await;
    }
    for conns in &mut tasks {
        db.process(conns, &providers, 0).await;
    }
    assert_eq!(db.leased("primary").await, 2);
    assert_eq!(server.stats.open(), 2);
    for conns in &mut tasks {
        conns.close(&db.pool).await.unwrap();
        assert_eq!(conns.leases().held().await, 0);
    }
    assert_eq!(db.leased("primary").await, 0);
    eventually("both sockets to close", async || server.stats.open() == 0).await;
    db.finish().await;
}

#[tokio::test]
async fn the_shared_budget_is_never_exceeded_by_retained_connections() {
    let Some(db) = Fixture::start(2).await else {
        return;
    };
    let server = ScriptedNntp::start(Script {
        rtt: Duration::from_millis(4),
        jitter: 0.5,
        ..Script::default()
    })
    .await;
    let providers = Arc::new([provider("primary", server.port)]);
    let releases = 14u64;
    for offset in 0..releases {
        db.seed(FIRST_ID + offset, 3).await;
    }
    // Sample the budget table for the whole run.
    let stop = Arc::new(AtomicBool::new(false));
    let sampler = tokio::spawn({
        let pool = db.pool.clone();
        let stop = stop.clone();
        async move {
            let mut peak = 0i64;
            while !stop.load(Ordering::SeqCst) {
                let leased: i64 = sqlx::query_scalar("SELECT COUNT(*) FROM release_fingerprint_connections WHERE provider='primary' AND lease_token IS NOT NULL AND lease_until>UTC_TIMESTAMP(6)").fetch_one(&pool).await.unwrap();
                peak = peak.max(leased);
                tokio::time::sleep(Duration::from_millis(3)).await;
            }
            peak
        }
    });
    // Five tasks compete for two rows.
    let contention = Arc::new(Contention::default());
    let mut tasks = vec![];
    for task in 0..5 {
        let pool = db.pool.clone();
        let providers = providers.clone();
        let contention = contention.clone();
        tasks.push(tokio::spawn(async move {
            let mut conns = Connections::new(format!("reuse-test-{task}"), IDLE, contention);
            let mut done = 0u64;
            while let Some(claim) = database::claim(&pool, "reuse-test").await.unwrap() {
                let collected = collect::run(&pool, &claim, &*providers, 0, 5, &mut conns)
                    .await
                    .unwrap();
                assert_eq!(collected.status, "complete");
                assert!(
                    database::finish(&pool, &claim, &collected.status, &collected.data)
                        .await
                        .unwrap()
                );
                conns.settle(&pool).await.unwrap();
                done += 1;
            }
            conns.close(&pool).await.unwrap();
            done
        }));
    }
    let mut total = 0;
    for task in tasks {
        total += task.await.unwrap();
    }
    stop.store(true, Ordering::SeqCst);
    let peak = sampler.await.unwrap();
    assert_eq!(total, releases);
    assert!(peak <= 2, "leased rows peaked at {peak}, budget is 2");
    assert!(
        server.stats.max_open() <= 2,
        "{} sockets were open at once, budget is 2",
        server.stats.max_open()
    );
    assert_eq!(db.leased("primary").await, 0);
    let complete: i64 = sqlx::query_scalar("SELECT COUNT(*) FROM release_fingerprint_metadata WHERE releases_id>=? AND status='complete'").bind(FIRST_ID).fetch_one(&db.pool).await.unwrap();
    assert_eq!(complete, releases as i64, "no lost or duplicated result");
    db.finish().await;
}

#[tokio::test]
async fn the_alternate_provider_keeps_its_cooldown_and_is_not_retained() {
    let Some(db) = Fixture::start(4).await else {
        return;
    };
    let primary = ScriptedNntp::start(Script::default()).await;
    let alternate = ScriptedNntp::start(Script::default()).await;
    let providers = [
        provider("primary", primary.port),
        provider("alternate", alternate.port),
    ];
    let mut conns = connections(&Arc::default());
    db.seed(FIRST_ID, 2).await;
    db.seed(FIRST_ID + 1, 2).await;
    db.seed(FIRST_ID + 2, 2).await;

    // Always consult the alternate: its socket and row are returned after every release.
    let first = db.process(&mut conns, &providers, 100).await;
    assert_eq!(first.status, "complete");
    assert_eq!(alternate.stats.accepted(), 1);
    assert_eq!(db.leased("alternate").await, 0);
    assert_eq!(
        db.leased("primary").await,
        1,
        "only the primary is retained"
    );

    // A shared cooldown is honoured while the primary connection keeps being reused.
    breaker::unavailable(&db.pool, "alternate").await.unwrap();
    let second = db.process(&mut conns, &providers, 100).await;
    assert_eq!(second.timings.connections_reused, 1);
    assert_eq!(
        alternate.stats.accepted(),
        1,
        "no connection during cooldown"
    );
    assert_eq!(
        second.data["providers"][1]["skipped_reason"],
        "recent_provider_failure"
    );

    // After the cooldown a fresh alternate connection performs the recovery probe.
    sqlx::query("UPDATE release_fingerprint_provider_state SET blocked_until=TIMESTAMPADD(SECOND,-1,UTC_TIMESTAMP(6)) WHERE provider='alternate'").execute(&db.pool).await.unwrap();
    let third = db.process(&mut conns, &providers, 100).await;
    assert_eq!(alternate.stats.accepted(), 2);
    assert_eq!(third.data["providers"][1]["profile"], "ok");
    let generation: Option<String> = sqlx::query_scalar(
        "SELECT generation FROM release_fingerprint_provider_state WHERE provider='alternate'",
    )
    .fetch_one(&db.pool)
    .await
    .unwrap();
    assert!(generation.is_none(), "recovery cleared the cooldown");
    assert_eq!(db.leased("alternate").await, 0);

    conns.close(&db.pool).await.unwrap();
    db.finish().await;
}

#[tokio::test]
async fn holders_hand_a_row_to_a_task_of_this_process_that_is_waiting() {
    let Some(db) = Fixture::start(1).await else {
        return;
    };
    let server = ScriptedNntp::start(Script::default()).await;
    let providers = [provider("primary", server.port)];
    let contention = Arc::new(Contention::default());
    let mut holder = connections(&contention);
    for offset in 0..3 {
        db.seed(FIRST_ID + offset, 2).await;
    }
    db.process(&mut holder, &providers, 0).await;
    assert_eq!(holder.idle_connections(), 1, "no one is waiting: retained");

    // Idle holder: the waiting task's row comes back at the next idle check.
    let waiter = |db: &Fixture| {
        let pool = db.pool.clone();
        let provider = providers[0].clone();
        let mut conns = connections(&contention);
        tokio::spawn(async move {
            let checkout = conns.checkout(&pool, &provider).await;
            (conns, checkout.map(|c| c.token))
        })
    };
    let first = waiter(&db);
    eventually("the task to start waiting", async || {
        contention.waiting() == 1
    })
    .await;
    holder.expire_idle(&db.pool).await.unwrap();
    assert_eq!(holder.idle_connections(), 0);
    let (mut conns, token) = tokio::time::timeout(Duration::from_secs(2), first)
        .await
        .unwrap()
        .unwrap();
    token.unwrap();
    conns.close(&db.pool).await.unwrap();
    assert_eq!(contention.waiting(), 0);

    // Busy holder: it does not retain its connection after the release it is running.
    db.process(&mut holder, &providers, 0).await;
    assert_eq!(holder.idle_connections(), 1);
    let second = waiter(&db);
    eventually("the task to start waiting", async || {
        contention.waiting() == 1
    })
    .await;
    db.process(&mut holder, &providers, 0).await;
    assert_eq!(
        holder.idle_connections(),
        0,
        "yielded at the end of its job"
    );
    let (mut conns, token) = tokio::time::timeout(Duration::from_secs(2), second)
        .await
        .unwrap()
        .unwrap();
    token.unwrap();
    conns.close(&db.pool).await.unwrap();
    assert_eq!(db.leased("primary").await, 0);
    db.finish().await;
}
