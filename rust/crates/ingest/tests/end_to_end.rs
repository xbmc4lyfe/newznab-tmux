//! A safe update and backfill pass through the real client, against a small news server on a local
//! socket and a real database. Skips when TEST_DATABASE_URL is unset.

use std::sync::Arc;
use std::time::Duration;

use nntmux_ingest::client::ClientConnector;
use nntmux_ingest::config::IngestConfig;
use nntmux_ingest::get_range::RangeMode;
use nntmux_ingest::nntp::Nntp;
use nntmux_ingest::run::{Connector, GroupJobs, Job, RangeJob, Runner};
use nntmux_ingest::store::NoHooks;
use nntmux_nntp::{NntpConfig, Provider, TlsOptions};
use nntmux_testkit::TestDb;
use tokio::io::{AsyncBufReadExt, AsyncWriteExt, BufReader};
use tokio::net::TcpListener;

const GROUP: &str = "alt.binaries.ingest.test";
const FIRST: u64 = 501;
const LAST: u64 = 1500;

struct Refused;

#[async_trait::async_trait]
impl Connector for Refused {
    async fn connect(&self) -> anyhow::Result<Box<dyn Nntp>> {
        anyhow::bail!("refused")
    }
}

fn row(number: u64) -> String {
    format!(
        "{number}\tUpload [01/01] - \"up.rar\" yEnc ({number}/5000)\tposter@example.com\t\
         26 Jun 2014 13:08:22 GMT\t<{number}@example>\t\t100\t2\tXref: news {GROUP}:{number}\r\n"
    )
}

/// Answers the commands ingest sends. Article 1005 doesn't exist.
async fn serve() -> u16 {
    let listener = TcpListener::bind("127.0.0.1:0").await.unwrap();
    let port = listener.local_addr().unwrap().port();
    tokio::spawn(async move {
        loop {
            let (socket, _) = listener.accept().await.unwrap();
            tokio::spawn(async move {
                let (read, mut write) = socket.into_split();
                let mut lines = BufReader::new(read).lines();
                write.write_all(b"200 test server\r\n").await.unwrap();
                while let Ok(Some(line)) = lines.next_line().await {
                    let reply = answer(&line);
                    if write.write_all(reply.as_bytes()).await.is_err() {
                        break;
                    }
                }
            });
        }
    });
    port
}

fn answer(line: &str) -> String {
    let upper = line.to_ascii_uppercase();
    if upper.starts_with("GROUP ") {
        return format!("211 1000 {FIRST} {LAST} {GROUP}\r\n");
    }
    if upper.starts_with("LIST OVERVIEW.FMT") {
        return "215 format\r\nSubject:\r\nFrom:\r\nDate:\r\nMessage-ID:\r\nReferences:\r\n:bytes\r\n:lines\r\nXref:full\r\n.\r\n".to_owned();
    }
    if upper.starts_with("LIST ACTIVE") {
        return format!("215 groups\r\n{GROUP} {LAST} {FIRST} y\r\nalt.not.ours 9 1 y\r\n.\r\n");
    }
    if let Some(range) = upper.strip_prefix("XOVER ") {
        let (first, last) = match range.split_once('-') {
            Some((first, last)) => (first.parse().unwrap(), last.parse().unwrap()),
            None => (range.parse().unwrap(), range.parse().unwrap()),
        };
        let rows: String = (first..=last)
            .filter(|number: &u64| (FIRST..=LAST).contains(number) && *number != 1005)
            .map(row)
            .collect();
        return format!("224 overview\r\n{rows}.\r\n");
    }
    "500 what\r\n".to_owned()
}

fn nntp_config(port: u16) -> NntpConfig {
    NntpConfig {
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
        primary_connections: 4,
        alternate_connections: 1,
        compressed_headers: false,
        use_alternate: false,
        alternate_article_fallback: false,
        fingerprint_breaker_seconds: 0,
        retries: 1,
    }
}

#[tokio::test]
async fn a_safe_pass_walks_a_group_through_the_real_client() {
    let Some(db) = TestDb::create().await.unwrap() else {
        return;
    };
    let pool = &db.pool;
    sqlx::query(
        "INSERT INTO usenet_groups (name, active, backfill, last_record, first_record, first_record_postdate, backfill_target)
         VALUES (?, 1, 1, 1000, 1000, '2014-01-01 00:00:00', 1)",
    )
    .bind(GROUP)
    .execute(pool)
    .await
    .unwrap();
    sqlx::query("INSERT IGNORE INTO processing_runtime_states (id) VALUES (1)")
        .execute(pool)
        .await
        .unwrap();
    let mut config = IngestConfig::load(pool).await.unwrap();
    config.message_buffer = 100;
    config.part_repair = true;
    config.backfill_threads = 2;
    config.backfill_quantity = 200;
    config.backfill_days_mode = 2;
    config.safe_backfill_date = chrono::NaiveDate::from_ymd_opt(2012, 6, 24).unwrap();
    let runner = Runner {
        pool: pool.clone(),
        config: Arc::new(config),
        connector: Arc::new(ClientConnector::new(nntp_config(serve().await))),
        hooks: Arc::new(NoHooks),
        stop: None,
        job_timeout: Duration::from_secs(30),
    };

    let result = runner.safe_binaries(None).await.unwrap();
    assert_eq!(result.failed, 0);
    let short: Vec<(String, u64, u64)> =
        sqlx::query_as("SELECT name, first_record, last_record FROM short_groups")
            .fetch_all(pool)
            .await
            .unwrap();
    assert_eq!(short, [(GROUP.to_owned(), FIRST, LAST)]);

    let (first, last): (u64, u64) =
        sqlx::query_as("SELECT first_record, last_record FROM usenet_groups WHERE name = ?")
            .bind(GROUP)
            .fetch_one(pool)
            .await
            .unwrap();
    assert_eq!(first, 1000);
    assert!(last > 1000, "the cursor stayed at {last}");
    let parts: i64 = sqlx::query_scalar("SELECT COUNT(*) FROM parts")
        .fetch_one(pool)
        .await
        .unwrap();
    let missing: Vec<u64> = sqlx::query_scalar("SELECT numberid FROM missed_parts")
        .fetch_all(pool)
        .await
        .unwrap();
    assert_eq!(missing, [1005]);
    assert_eq!(parts, i64::try_from(last - 1000).unwrap() - 1);
    let stored: (String, u32) =
        sqlx::query_as("SELECT messageid, size FROM parts WHERE number = 1001")
            .fetch_one(pool)
            .await
            .unwrap();
    assert_eq!(stored, ("<1001@example>".to_owned(), 100));
    let cross: Vec<String> = sqlx::query_scalar("SELECT group_name FROM collection_groups")
        .fetch_all(pool)
        .await
        .unwrap();
    assert_eq!(cross, [GROUP]);

    // An exhausted group isn't picked, so it can't keep others from their turn.
    sqlx::query("INSERT INTO short_groups (name, first_record, last_record) VALUES ('exhausted.group', 5, 9)")
        .execute(pool)
        .await
        .unwrap();
    sqlx::query(
        "INSERT INTO usenet_groups (name, backfill, last_record, first_record, first_record_postdate)
         VALUES ('exhausted.group', 1, 9, 5, '2013-01-01 00:00:00')",
    )
    .execute(pool)
    .await
    .unwrap();

    let result = runner.safe_backfill().await.unwrap();
    // 499 older articles, over the budget of 200 for each of 2 threads, in chunks of 100.
    assert_eq!((result.finished, result.failed), (4, 0));
    let first: u64 = sqlx::query_scalar("SELECT first_record FROM usenet_groups WHERE name = ?")
        .bind(GROUP)
        .fetch_one(pool)
        .await
        .unwrap();
    assert_eq!(first, 600);

    // A job that can't connect still leaves its range queued, since siblings can pass it.
    sqlx::query("DELETE FROM missed_parts")
        .execute(pool)
        .await
        .unwrap();
    let unreachable = Runner {
        connector: Arc::new(Refused),
        ..runner.clone()
    };
    let plan = vec![GroupJobs {
        group: 1,
        jobs: vec![Job::Range(RangeJob {
            mode: RangeMode::Backfill,
            group: GROUP.to_owned(),
            first: 590,
            last: 591,
            contiguous: false,
            repair: false,
        })],
    }];
    assert_eq!(unreachable.run(plan, 1).await.failed, 1);
    let queued: Vec<u64> =
        sqlx::query_scalar("SELECT numberid FROM missed_parts ORDER BY numberid")
            .fetch_all(pool)
            .await
            .unwrap();
    assert_eq!(queued, [590, 591]);
    db.drop_database().await.unwrap();
}
