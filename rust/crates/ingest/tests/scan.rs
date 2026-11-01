//! The update, repair, backfill and range flows against a real database and a scripted server.
//! Skips when TEST_DATABASE_URL is unset.

use std::collections::{BTreeMap, VecDeque};

use async_trait::async_trait;
use nntmux_ingest::backfill::{Order, Target};
use nntmux_ingest::config::IngestConfig;
use nntmux_ingest::get_range::{RangeMode, RangeRequest, get_range};
use nntmux_ingest::groups::{self, Group, ServerGroup};
use nntmux_ingest::header::Overview;
use nntmux_ingest::nntp::{Nntp, NntpFailure, SelectedGroup};
use nntmux_ingest::scan::{Ingest, ScanKind};
use nntmux_ingest::store::NoHooks;
use nntmux_testkit::TestDb;
use sqlx::MySqlPool;

const GROUP: &str = "alt.binaries.ingest.test";

/// A server holding articles by number, with failures queued per XOVER.
#[derive(Default)]
struct FakeServer {
    articles: BTreeMap<u64, Overview>,
    first: u64,
    last: u64,
    xover_failures: VecDeque<NntpFailure>,
    requests: Vec<(u64, u64)>,
    reconnects: Vec<bool>,
    compression_restored: u32,
}

impl FakeServer {
    fn with_articles(numbers: impl IntoIterator<Item = u64>, first: u64, last: u64) -> Self {
        Self {
            articles: numbers
                .into_iter()
                .map(|number| (number, article(number)))
                .collect(),
            first,
            last,
            ..Self::default()
        }
    }
}

fn article(number: u64) -> Overview {
    Overview {
        number,
        subject: format!("Upload [01/01] - \"up.rar\" yEnc ({number}/100000)").into_bytes(),
        from: b"poster@example.com".to_vec(),
        date: b"26 Jun 2014 13:08:22 GMT".to_vec(),
        message_id: format!("<{number}@example>").into_bytes(),
        bytes: 100,
        xref: Vec::new(),
    }
}

#[async_trait]
impl Nntp for FakeServer {
    async fn select_group(&mut self, group: &str) -> Result<SelectedGroup, NntpFailure> {
        Ok(SelectedGroup {
            name: group.to_owned(),
            first: self.first,
            last: self.last,
        })
    }

    async fn xover(&mut self, first: u64, last: u64) -> Result<Vec<Overview>, NntpFailure> {
        self.requests.push((first, last));
        if let Some(failure) = self.xover_failures.pop_front() {
            return Err(failure);
        }
        Ok(self
            .articles
            .range(first..=last)
            .map(|(_, row)| row.clone())
            .collect())
    }

    async fn reconnect(&mut self, compression: bool) -> Result<(), NntpFailure> {
        self.reconnects.push(compression);
        Ok(())
    }

    async fn enable_compression(&mut self) -> Result<(), NntpFailure> {
        self.compression_restored += 1;
        Ok(())
    }

    async fn list_groups(&mut self) -> Result<Vec<ServerGroup>, NntpFailure> {
        Ok(Vec::new())
    }
}

fn failure(code: u16) -> NntpFailure {
    NntpFailure {
        code: Some(code),
        message: "scripted".to_owned(),
    }
}

async fn setup(db: &TestDb, last_record: u64, first_record: u64) -> (Group, IngestConfig) {
    sqlx::query(
        "INSERT INTO usenet_groups (name, active, backfill, last_record, first_record, first_record_postdate)
         VALUES (?, 1, 1, ?, ?, IF(? = 0, NULL, '2014-01-01 00:00:00'))",
    )
    .bind(GROUP)
    .bind(last_record)
    .bind(first_record)
    .bind(first_record)
    .execute(&db.pool)
    .await
    .unwrap();
    sqlx::query("INSERT IGNORE INTO processing_runtime_states (id) VALUES (1)")
        .execute(&db.pool)
        .await
        .unwrap();
    let mut config = IngestConfig::load(&db.pool).await.unwrap();
    config.message_buffer = 100;
    config.new_group_messages_to_scan = 50;
    config.new_group_scan_by_days = false;
    config.part_repair = true;
    config.safe_part_repair = false;
    (
        groups::by_name(&db.pool, GROUP).await.unwrap().unwrap(),
        config,
    )
}

async fn missed_numbers(pool: &MySqlPool) -> Vec<u64> {
    sqlx::query_scalar("SELECT numberid FROM missed_parts ORDER BY numberid")
        .fetch_all(pool)
        .await
        .unwrap()
}

async fn parts(pool: &MySqlPool) -> i64 {
    sqlx::query_scalar("SELECT COUNT(*) FROM parts")
        .fetch_one(pool)
        .await
        .unwrap()
}

async fn cursor(pool: &MySqlPool) -> (u64, u64) {
    sqlx::query_as("SELECT first_record, last_record FROM usenet_groups WHERE name = ?")
        .bind(GROUP)
        .fetch_one(pool)
        .await
        .unwrap()
}

#[tokio::test]
async fn update_stores_new_articles_and_queues_what_the_server_skipped() {
    let Some(db) = TestDb::create().await.unwrap() else {
        return;
    };
    let (group, config) = setup(&db, 1000, 900).await;
    // 1001..=1010 of the 20 to fetch, with 1005 missing. 40 new articles leave half for later.
    let numbers = (1001..=1020).filter(|number| *number != 1005);
    let mut server = FakeServer::with_articles(numbers, 1, 1040);
    let mut ingest = Ingest::new(&db.pool, &config, &NoHooks, &mut server, None);
    ingest.update_group(&group, 0).await.unwrap();
    assert_eq!(cursor(&db.pool).await, (900, 1020));
    assert_eq!(parts(&db.pool).await, 19);
    assert_eq!(missed_numbers(&db.pool).await, [1005]);
    let attempts: i8 = sqlx::query_scalar("SELECT attempts FROM missed_parts")
        .fetch_one(&db.pool)
        .await
        .unwrap();
    assert_eq!(attempts, 1);
    db.drop_database().await.unwrap();
}

#[tokio::test]
async fn a_new_group_records_its_first_article() {
    let Some(db) = TestDb::create().await.unwrap() else {
        return;
    };
    let (group, config) = setup(&db, 0, 0).await;
    let mut server = FakeServer::with_articles(860..=900, 1, 1000);
    let mut ingest = Ingest::new(&db.pool, &config, &NoHooks, &mut server, None);
    ingest.update_group(&group, 0).await.unwrap();
    // 50 to scan plus the 100 buffer back from 1000, and the buffer stays for the next pass.
    assert_eq!(server.requests, [(851, 900)]);
    assert_eq!(cursor(&db.pool).await, (860, 900));
    let date: Option<chrono::NaiveDateTime> =
        sqlx::query_scalar("SELECT first_record_postdate FROM usenet_groups WHERE name = ?")
            .bind(GROUP)
            .fetch_one(&db.pool)
            .await
            .unwrap();
    assert_eq!(date.unwrap().to_string(), "2014-06-26 13:08:22");
    db.drop_database().await.unwrap();
}

#[tokio::test]
async fn a_failed_overview_retries_uncompressed_and_a_second_failure_keeps_the_cursor() {
    let Some(db) = TestDb::create().await.unwrap() else {
        return;
    };
    let (group, config) = setup(&db, 1000, 900).await;
    let mut server = FakeServer::with_articles(1001..=1020, 1, 1040);
    server.xover_failures = VecDeque::from([failure(502), failure(502)]);
    let mut ingest = Ingest::new(&db.pool, &config, &NoHooks, &mut server, None);
    ingest.update_group(&group, 0).await.unwrap();
    assert_eq!(server.reconnects, [false]);
    assert_eq!(server.compression_restored, 1);
    assert_eq!(cursor(&db.pool).await, (900, 1000));
    assert_eq!(parts(&db.pool).await, 0);
    assert!(missed_numbers(&db.pool).await.is_empty());
    db.drop_database().await.unwrap();
}

#[tokio::test]
async fn a_423_completes_an_empty_window() {
    let Some(db) = TestDb::create().await.unwrap() else {
        return;
    };
    let (group, config) = setup(&db, 1000, 900).await;
    let mut server = FakeServer::with_articles([], 1, 1040);
    server.xover_failures = VecDeque::from([failure(423)]);
    let mut ingest = Ingest::new(&db.pool, &config, &NoHooks, &mut server, None);
    let outcome = ingest
        .scan(&group, 1001, 1020, ScanKind::Update, None)
        .await
        .unwrap();
    assert_eq!(outcome.completed_end, Some(1020));
    assert_eq!(outcome.summary, None);
    assert!(server.reconnects.is_empty());
    db.drop_database().await.unwrap();
}

#[tokio::test]
async fn part_repair_removes_what_it_finds_and_gives_up_after_its_tries() {
    let Some(db) = TestDb::create().await.unwrap() else {
        return;
    };
    let (group, mut config) = setup(&db, 1000, 900).await;
    config.part_repair_max_tries = 2;
    let mut server = FakeServer::with_articles([500], 1, 1040);
    let mut ingest = Ingest::new(&db.pool, &config, &NoHooks, &mut server, None);
    let missed = ingest.missed();
    missed.add(&db.pool, &[500, 501], group.id).await.unwrap();
    // Attempts start at one, so 501 reaches the limit on this pass and is dropped.
    ingest.part_repair(&group).await.unwrap();
    assert_eq!(parts(&db.pool).await, 1);
    assert!(missed_numbers(&db.pool).await.is_empty());
    db.drop_database().await.unwrap();
}

#[tokio::test]
async fn a_full_backlog_defers_the_range_without_fetching() {
    let Some(db) = TestDb::create().await.unwrap() else {
        return;
    };
    let (group, mut config) = setup(&db, 1000, 900).await;
    config.verification_required = true;
    config.verification_pending_bytes = 10;
    sqlx::query(
        "INSERT INTO release_verification_state (id, pending_bytes) VALUES (1, 100)
         ON DUPLICATE KEY UPDATE pending_bytes = 100",
    )
    .execute(&db.pool)
    .await
    .unwrap();
    let mut server = FakeServer::with_articles(1001..=1003, 1, 1040);
    let mut ingest = Ingest::new(&db.pool, &config, &NoHooks, &mut server, None);
    assert!(
        ingest
            .scan(&group, 1001, 1003, ScanKind::Update, None)
            .await
            .is_err()
    );
    assert!(server.requests.is_empty());
    assert_eq!(missed_numbers(&db.pool).await, [1001, 1002, 1003]);
    db.drop_database().await.unwrap();
}

#[tokio::test]
async fn backfill_walks_down_to_its_target() {
    let Some(db) = TestDb::create().await.unwrap() else {
        return;
    };
    let (_, config) = setup(&db, 1000, 900).await;
    let mut server = FakeServer::with_articles(600..=899, 1, 1040);
    let mut ingest = Ingest::new(&db.pool, &config, &NoHooks, &mut server, None);
    ingest
        .backfill_all(Some(GROUP), Target::Articles(250), Order::Name)
        .await
        .unwrap();
    assert_eq!(server.requests, [(800, 899), (700, 799), (650, 699)]);
    assert_eq!(cursor(&db.pool).await, (650, 1000));
    assert_eq!(parts(&db.pool).await, 250);
    db.drop_database().await.unwrap();
}

#[tokio::test]
async fn backfill_stops_at_the_servers_oldest_article() {
    let Some(db) = TestDb::create().await.unwrap() else {
        return;
    };
    let (_, config) = setup(&db, 1000, 900).await;
    let mut server = FakeServer::with_articles([], 900, 1040);
    let mut ingest = Ingest::new(&db.pool, &config, &NoHooks, &mut server, None);
    ingest
        .backfill_all(Some(GROUP), Target::Articles(250), Order::Name)
        .await
        .unwrap();
    assert!(server.requests.is_empty());
    assert_eq!(cursor(&db.pool).await, (900, 1000));
    db.drop_database().await.unwrap();
}

fn range(mode: RangeMode, first: u64, last: u64, contiguous: bool) -> RangeRequest {
    RangeRequest {
        mode,
        group: GROUP.to_owned(),
        first,
        last,
        contiguous,
        repair: false,
    }
}

#[tokio::test]
async fn contiguous_ranges_advance_the_cursor_in_order_only() {
    let Some(db) = TestDb::create().await.unwrap() else {
        return;
    };
    let (_, config) = setup(&db, 1000, 900).await;
    let mut server = FakeServer::with_articles(1001..=1020, 1, 1040);
    let mut ingest = Ingest::new(&db.pool, &config, &NoHooks, &mut server, None);
    // A range beyond the cursor must wait for the one before it.
    let refused = get_range(&mut ingest, &range(RangeMode::Binaries, 1011, 1020, true)).await;
    assert!(refused.is_err());
    assert_eq!(cursor(&db.pool).await, (900, 1000));
    get_range(&mut ingest, &range(RangeMode::Binaries, 1001, 1010, true))
        .await
        .unwrap();
    assert_eq!(cursor(&db.pool).await, (900, 1010));
    get_range(&mut ingest, &range(RangeMode::Binaries, 1011, 1020, true))
        .await
        .unwrap();
    assert_eq!(cursor(&db.pool).await, (900, 1020));
    db.drop_database().await.unwrap();
}

#[tokio::test]
async fn backfill_ranges_only_move_the_first_record_down() {
    let Some(db) = TestDb::create().await.unwrap() else {
        return;
    };
    let (_, config) = setup(&db, 1000, 900).await;
    let mut server = FakeServer::with_articles(801..=850, 1, 1040);
    let mut ingest = Ingest::new(&db.pool, &config, &NoHooks, &mut server, None);
    get_range(&mut ingest, &range(RangeMode::Backfill, 801, 850, false))
        .await
        .unwrap();
    assert_eq!(cursor(&db.pool).await, (801, 1000));
    db.drop_database().await.unwrap();
}

#[tokio::test]
async fn a_stop_request_defers_a_parallel_range() {
    let Some(db) = TestDb::create().await.unwrap() else {
        return;
    };
    let (_, config) = setup(&db, 1000, 900).await;
    sqlx::query("UPDATE processing_runtime_states SET stop_requested = 1 WHERE id = 1")
        .execute(&db.pool)
        .await
        .unwrap();
    let mut server = FakeServer::with_articles(801..=802, 1, 1040);
    let mut ingest = Ingest::new(&db.pool, &config, &NoHooks, &mut server, None);
    get_range(&mut ingest, &range(RangeMode::Backfill, 801, 802, false))
        .await
        .unwrap();
    assert!(server.requests.is_empty());
    assert_eq!(missed_numbers(&db.pool).await, [801, 802]);
    db.drop_database().await.unwrap();
}

#[tokio::test]
async fn finds_the_article_for_a_date() {
    let Some(db) = TestDb::create().await.unwrap() else {
        return;
    };
    let (_, config) = setup(&db, 1000, 900).await;
    // One article a day, newest at 1000, with a gap that a probe has to step over.
    let now = chrono::Utc::now();
    let articles = (1..=1000_u64)
        .filter(|number| !(490..=510).contains(number))
        .map(|number| {
            let date = now - chrono::Duration::days((1000 - number) as i64);
            let mut row = article(number);
            row.date = date.to_rfc2822().into_bytes();
            (number, row)
        })
        .collect();
    let mut server = FakeServer {
        articles,
        first: 1,
        last: 1000,
        ..FakeServer::default()
    };
    let selected = server.select_group(GROUP).await.unwrap();
    let mut ingest = Ingest::new(&db.pool, &config, &NoHooks, &mut server, None);
    let found = ingest.day_to_post(100, &selected).await.unwrap();
    assert!(found.abs_diff(900) <= 1, "found {found}");
    // Goals beyond either end return that end.
    assert_eq!(ingest.day_to_post(5000, &selected).await.unwrap(), 1);
    db.drop_database().await.unwrap();
}
