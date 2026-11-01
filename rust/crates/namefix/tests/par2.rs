//! The PAR2 rename path against a real database, Redis and a small scripted NNTP server on a
//! local socket. Nothing reaches the network.

use std::sync::{Arc, Mutex};
use std::time::Duration;

use md5::{Digest, Md5};
use nntmux_categorize::{Categorizer, cat};
use nntmux_namefix::fixnames_chain;
use nntmux_namefix::par2_accumulator::PacketAccumulator;
use nntmux_namefix::par2_apply::{Context, Expected, apply_inventory};
use nntmux_namefix::par2_check::{Par2Checker, Par2Settings};
use nntmux_namefix::par2_checkpoint::{self as checkpoints, ArticleIdentity, Save};
use nntmux_namefix::par2_nntp::NntpArticles;
use nntmux_namefix::par2_probe::{BatchProbe, fix_names_with_par2};
use nntmux_namefix::par2_progress;
use nntmux_namefix::par2_support::{Claim, Guard, Switch, Unguarded};
use nntmux_namefix::queries::FixNamesConfig;
use nntmux_namefix::release::{Release, Snapshot};
use nntmux_namefix::service::{NameFixer, Par2Outcome, Par2Probe};
use nntmux_namefix::update::{NoSearch, ReleaseSearch, ReleaseUpdater};
use nntmux_nntp::{NntpConfig, Provider, TlsOptions};
use nntmux_predb::filename::fixer_cleaner;
use nntmux_testkit::TestDb;
use redis::aio::ConnectionManager;
use sqlx::MySqlPool;
use tokio::io::{AsyncBufReadExt, AsyncWriteExt, BufReader};
use tokio::net::TcpListener;

const GUID: &str = "0123456789abcdef0123456789abcdef01234567";
const OBFUSCATED: &str = "a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6";
const SCENE: &str = "Some.Movie.2021.1080p.BluRay.x264-GROUP";

// --- packets ---------------------------------------------------------------------------------

fn packet(set: &[u8; 16], kind: &[u8; 16], body: &[u8]) -> Vec<u8> {
    let mut tail = set.to_vec();
    tail.extend_from_slice(kind);
    tail.extend_from_slice(body);
    let mut out = b"PAR2\0PKT".to_vec();
    out.extend_from_slice(&(64 + body.len() as u64).to_le_bytes());
    out.extend_from_slice(&Md5::digest(&tail));
    out.extend_from_slice(&tail);
    out
}

fn padded(name: &str) -> Vec<u8> {
    let mut bytes = name.as_bytes().to_vec();
    while !bytes.len().is_multiple_of(4) {
        bytes.push(0);
    }
    bytes
}

fn main_packet(set: &[u8; 16], ids: &[[u8; 16]]) -> Vec<u8> {
    let mut body = 16384u64.to_le_bytes().to_vec();
    body.extend_from_slice(&(ids.len() as u32).to_le_bytes());
    for id in ids {
        body.extend_from_slice(id);
    }
    packet(set, b"PAR 2.0\0Main\0\0\0\0", &body)
}

fn desc_packet(set: &[u8; 16], id: &[u8; 16], name: &str, size: u64) -> Vec<u8> {
    let mut body = id.to_vec();
    body.extend_from_slice(&Md5::digest(format!("{name}-full")));
    body.extend_from_slice(&Md5::digest(format!("{name}-16k")));
    body.extend_from_slice(&size.to_le_bytes());
    body.extend_from_slice(&padded(name));
    packet(set, b"PAR 2.0\0FileDesc", &body)
}

fn par2_stream(name: &str, size: u64) -> Vec<u8> {
    let set = [7u8; 16];
    let id = [9u8; 16];
    let mut stream = main_packet(&set, &[id]);
    stream.extend(desc_packet(&set, &id, name, size));
    stream
}

// --- yEnc ------------------------------------------------------------------------------------

/// A complete single-part article, which is all a one-segment candidate needs.
fn article(data: &[u8], filename: &str) -> Vec<u8> {
    nntmux_yenc::encode(data, filename.as_bytes(), 128, true).expect("yEnc")
}

// --- the server ------------------------------------------------------------------------------

#[derive(Clone)]
enum Reply {
    Body(Vec<u8>),
    Missing,
}

struct Server {
    port: u16,
    requests: Arc<Mutex<Vec<String>>>,
}

impl Server {
    fn bodies(&self) -> usize {
        self.requests
            .lock()
            .unwrap()
            .iter()
            .filter(|line| line.starts_with("BODY"))
            .count()
    }
}

/// Answers GROUP and BODY from a table of Message-ID to reply, and every connection alike.
async fn serve(articles: Vec<(&'static str, Reply)>) -> Server {
    let listener = TcpListener::bind("127.0.0.1:0").await.unwrap();
    let port = listener.local_addr().unwrap().port();
    let requests = Arc::new(Mutex::new(Vec::new()));
    let log = Arc::clone(&requests);
    tokio::spawn(async move {
        loop {
            let Ok((stream, _)) = listener.accept().await else {
                return;
            };
            let (log, articles) = (Arc::clone(&log), articles.clone());
            tokio::spawn(async move {
                let mut io = BufReader::new(stream);
                io.write_all(b"200 ready\r\n").await.unwrap();
                loop {
                    let mut line = String::new();
                    if io.read_line(&mut line).await.unwrap_or(0) == 0 {
                        return;
                    }
                    let line = line.trim_end().to_owned();
                    log.lock().unwrap().push(line.clone());
                    if line.starts_with("GROUP") {
                        let name = line.trim_start_matches("GROUP ");
                        io.write_all(format!("211 1 1 1 {name}\r\n").as_bytes())
                            .await
                            .unwrap();
                    } else if let Some(id) = line.strip_prefix("BODY <") {
                        let id = id.trim_end_matches('>');
                        match articles.iter().find(|(known, _)| *known == id) {
                            Some((_, Reply::Body(body))) => {
                                io.write_all(b"222 0 <x> body\r\n").await.unwrap();
                                for chunk in body.split_inclusive(|b| *b == b'\n') {
                                    if chunk.starts_with(b".") {
                                        io.write_all(b".").await.unwrap();
                                    }
                                    io.write_all(chunk).await.unwrap();
                                }
                                io.write_all(b"\r\n.\r\n").await.unwrap();
                            }
                            _ => io.write_all(b"430 no such article\r\n").await.unwrap(),
                        }
                    } else {
                        io.write_all(b"500 what\r\n").await.unwrap();
                    }
                }
            });
        }
    });
    Server { port, requests }
}

fn nntp_config(port: u16) -> NntpConfig {
    NntpConfig {
        primary: Provider {
            host: "127.0.0.1".into(),
            port,
            tls: false,
            username: String::new(),
            password: String::new(),
            socket_timeout: Duration::from_secs(2),
        },
        alternate: None,
        tls: TlsOptions::default(),
        primary_connections: 1,
        alternate_connections: 1,
        compressed_headers: false,
        use_alternate: false,
        alternate_article_fallback: false,
        fingerprint_breaker_seconds: 900,
        retries: 1,
    }
}

// --- the database ----------------------------------------------------------------------------

async fn redis() -> Option<ConnectionManager> {
    let url = std::env::var("TEST_REDIS_URL").ok()?;
    let client = redis::Client::open(url).ok()?;
    ConnectionManager::new(client).await.ok()
}

fn prefix() -> String {
    format!("test{:016x}:", rand::random::<u64>())
}

async fn insert_group(pool: &MySqlPool, id: i64, name: &str) {
    sqlx::query("INSERT INTO usenet_groups (id, name) VALUES (?, ?) ON DUPLICATE KEY UPDATE name = VALUES(name)")
        .bind(id)
        .bind(name)
        .execute(pool)
        .await
        .expect("group");
}

async fn insert_release(pool: &MySqlPool, id: i64, guid: &str, name: &str, size: i64, group: i64) {
    let columns: Vec<(String, String)> = sqlx::query_as(
        "SELECT COLUMN_NAME, COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() \
         AND TABLE_NAME = 'releases' AND IS_NULLABLE = 'NO' AND COLUMN_DEFAULT IS NULL AND EXTRA NOT LIKE '%auto_increment%'",
    )
    .fetch_all(pool)
    .await
    .expect("columns");
    let mut names = vec![
        "id".to_owned(),
        "guid".to_owned(),
        "leftguid".to_owned(),
        "name".to_owned(),
        "searchname".to_owned(),
        "size".to_owned(),
        "categories_id".to_owned(),
        "groups_id".to_owned(),
        "nzbstatus".to_owned(),
        "postdate".to_owned(),
    ];
    let mut values = vec![
        id.to_string(),
        format!("'{guid}'"),
        format!("'{}'", &guid[..1]),
        format!("'{name}'"),
        format!("'{name}'"),
        size.to_string(),
        cat::OTHER_MISC.to_string(),
        group.to_string(),
        "1".to_owned(),
        "'2024-05-06 07:08:09'".to_owned(),
    ];
    for (column, kind) in columns {
        if names.iter().any(|known| known.trim_matches('`') == column) {
            continue;
        }
        names.push(format!("`{column}`"));
        values.push(if kind.contains("int") {
            "0".to_owned()
        } else if kind.contains("date") || kind.contains("time") {
            "NOW()".to_owned()
        } else {
            format!("'{id}'")
        });
    }
    sqlx::query(sqlx::AssertSqlSafe(format!(
        "INSERT INTO releases ({}) VALUES ({})",
        names.join(", "),
        values.join(", ")
    )))
    .execute(pool)
    .await
    .expect("release");
}

type Segments<'a> = &'a [(i64, i64, &'a str)];

fn nzb(files: &[(&str, Segments<'_>)]) -> String {
    let mut xml =
        String::from(r#"<?xml version="1.0"?><nzb xmlns="http://www.newzbin.com/DTD/2003/nzb">"#);
    for (subject, segments) in files {
        xml.push_str(&format!(
            r#"<file poster="p" date="1" subject="{}"><groups><group>alt.test</group></groups><segments>"#,
            subject.replace('"', "&quot;")
        ));
        for (number, bytes, id) in *segments {
            xml.push_str(&format!(
                r#"<segment number="{number}" bytes="{bytes}">{id}</segment>"#
            ));
        }
        xml.push_str("</segments></file>");
    }
    xml.push_str("</nzb>");
    xml
}

async fn store_nzb(pool: &MySqlPool, release_id: i64, guid: &str, xml: &str) {
    let compressed = nntmux_verify::nzb::compress(xml.as_bytes()).expect("gzip");
    sqlx::query(
        "INSERT INTO release_nzb_contents (guid, releases_id, body, source_digest, generation, decoded_bytes, created_at, updated_at) \
         VALUES (?, ?, ?, ?, 'g', ?, NOW(6), NOW(6))",
    )
    .bind(guid)
    .bind(release_id)
    .bind(compressed)
    .bind(nntmux_verify::nzb::digest(xml.as_bytes()))
    .bind(xml.len() as u64)
    .execute(pool)
    .await
    .expect("nzb");
}

struct Counting(Mutex<Vec<i64>>);

#[async_trait::async_trait]
impl ReleaseSearch for Counting {
    async fn update_release(&self, release_id: i64) {
        self.0.lock().unwrap().push(release_id);
    }
}

async fn checker(
    pool: &MySqlPool,
    redis: ConnectionManager,
    port: u16,
    search: Arc<dyn ReleaseSearch>,
    key_prefix: &str,
) -> Par2Checker {
    let categorizer = Categorizer::load(pool).await.expect("categorizer");
    let updater = ReleaseUpdater::new(pool.clone(), categorizer, search.clone(), fixer_cleaner);
    let config = FixNamesConfig::from_env();
    Par2Checker {
        fixer: NameFixer::new(updater, config.clone()),
        search,
        redis,
        articles: Box::new(NntpArticles::new(nntp_config(port))),
        config,
        settings: Par2Settings {
            add_par2: true,
            alternate: false,
            lease_seconds: 600,
            key_prefix: key_prefix.to_owned(),
        },
    }
}

async fn status(pool: &MySqlPool, id: i64) -> (String, i64, i64, i64) {
    sqlx::query_as(
        "SELECT searchname, CAST(proc_par2 AS SIGNED), CAST(isrenamed AS SIGNED), CAST(predb_id AS SIGNED) FROM releases WHERE id = ?",
    )
    .bind(id)
    .fetch_one(pool)
    .await
    .expect("status")
}

async fn count(pool: &MySqlPool, sql: &str, id: i64) -> i64 {
    sqlx::query_scalar(sqlx::AssertSqlSafe(sql.to_owned()))
        .bind(id)
        .fetch_one(pool)
        .await
        .expect("count")
}

#[allow(clippy::too_many_arguments)]
fn save_request<'a>(
    snapshot: &'a Snapshot,
    manifest: &'a str,
    candidate: &'a str,
    next: i64,
    accumulator: Option<&'a PacketAccumulator>,
    block: Option<&'static str>,
    identity: Option<&'a ArticleIdentity>,
    max: i64,
) -> Save<'a> {
    Save {
        snapshot,
        manifest_signature: manifest,
        candidate_signature: candidate,
        next_segment: next,
        retry_segments: &[],
        accumulator,
        segment_count: 4,
        max_state_bytes: max,
        blocked_reason: block,
        decoded_offset: 50,
        identity,
    }
}

// --- tests -----------------------------------------------------------------------------------

#[tokio::test]
async fn renames_a_release_from_its_par2_index_and_records_the_evidence() {
    let Some(db) = TestDb::create().await.expect("db") else {
        return;
    };
    let Some(redis) = redis().await else {
        return;
    };
    insert_group(&db.pool, 5, "alt.test").await;
    insert_release(&db.pool, 1, GUID, OBFUSCATED, 50_000_000, 5).await;
    let data = par2_stream(&format!("{SCENE}.mkv"), 40_000_000);
    store_nzb(
        &db.pool,
        1,
        GUID,
        &nzb(&[
            ("\"data.rar\" yEnc (1/1)", &[(1, 45_000_000, "rar1@x")]),
            (
                "\"x.par2\" yEnc (1/1)",
                &[(1, data.len() as i64 + 200, "idx@x")],
            ),
        ]),
    )
    .await;
    let server = serve(vec![("idx@x", Reply::Body(article(&data, "x.par2")))]).await;
    let searched = Arc::new(Counting(Mutex::new(Vec::new())));
    let mut checker = checker(&db.pool, redis, server.port, searched.clone(), &prefix()).await;

    let (outcome, stats) = checker.check(GUID, 1, 5, 1, None, None).await;

    assert_eq!(outcome, Par2Outcome::Renamed);
    assert_eq!((stats.candidates_probed, stats.articles_received), (1, 1));
    let (searchname, proc_par2, renamed, _) = status(&db.pool, 1).await;
    assert_eq!(searchname, SCENE);
    assert_eq!((proc_par2, renamed), (1, 1));
    // The descriptor and hash evidence, the release file and its count were all recorded.
    assert_eq!(
        count(
            &db.pool,
            "SELECT COUNT(*) FROM par2_descriptor_evidence WHERE releases_id = ?",
            1
        )
        .await,
        1
    );
    assert_eq!(
        count(
            &db.pool,
            "SELECT COUNT(*) FROM par_file_hashes WHERE releases_id = ?",
            1
        )
        .await,
        1
    );
    assert_eq!(
        count(
            &db.pool,
            "SELECT COUNT(*) FROM par_hashes WHERE releases_id = ?",
            1
        )
        .await,
        1
    );
    assert_eq!(
        count(
            &db.pool,
            "SELECT COUNT(*) FROM release_files WHERE releases_id = ?",
            1
        )
        .await,
        1
    );
    assert_eq!(
        count(
            &db.pool,
            "SELECT CAST(rarinnerfilecount AS SIGNED) FROM releases WHERE id = ?",
            1
        )
        .await,
        1
    );
    // Progress for a finished release is dropped, and the search index heard about the files
    // and the rename.
    assert_eq!(
        count(
            &db.pool,
            "SELECT COUNT(*) FROM par2_rename_candidate_progress WHERE releases_id = ?",
            1
        )
        .await,
        0
    );
    assert!(searched.0.lock().unwrap().contains(&1));
    db.drop_database().await.expect("drop");
}

#[tokio::test]
async fn a_failed_fetch_is_retryable_and_never_marks_the_release_processed() {
    let Some(db) = TestDb::create().await.expect("db") else {
        return;
    };
    let Some(redis) = redis().await else {
        return;
    };
    insert_group(&db.pool, 5, "alt.test").await;
    insert_release(&db.pool, 1, GUID, OBFUSCATED, 50_000_000, 5).await;
    store_nzb(
        &db.pool,
        1,
        GUID,
        &nzb(&[
            ("\"data.rar\" yEnc (1/1)", &[(1, 45_000_000, "rar1@x")]),
            ("\"x.par2\" yEnc (1/1)", &[(1, 5000, "gone@x")]),
        ]),
    )
    .await;
    let server = serve(vec![("gone@x", Reply::Missing)]).await;
    let mut checker = checker(&db.pool, redis, server.port, Arc::new(NoSearch), &prefix()).await;

    let (outcome, stats) = checker.check(GUID, 1, 5, 1, None, None).await;

    assert_eq!(outcome, Par2Outcome::Retryable);
    assert_eq!((stats.candidates_probed, stats.articles_received), (1, 0));
    let (_, proc_par2, renamed, _) = status(&db.pool, 1).await;
    assert_eq!((proc_par2, renamed), (0, 0));
    // The outer cursor moved past the candidate but keeps it to retry.
    let progress = par2_progress::get(&db.pool, 1)
        .await
        .expect("progress")
        .expect("row");
    assert_eq!((progress.offset, progress.retry_indexes), (1, vec![0]));
    // A second attempt asks for the article again rather than trusting the failure.
    let (again, _) = checker.check(GUID, 1, 5, 1, None, None).await;
    assert_eq!(again, Par2Outcome::Retryable);
    assert_eq!(server.bodies(), 2);
    db.drop_database().await.expect("drop");
}

#[tokio::test]
async fn a_fully_read_set_that_names_nothing_usable_ends_as_no_match() {
    let Some(db) = TestDb::create().await.expect("db") else {
        return;
    };
    let Some(redis) = redis().await else {
        return;
    };
    insert_group(&db.pool, 5, "alt.test").await;
    insert_release(&db.pool, 1, GUID, OBFUSCATED, 50_000_000, 5).await;
    let data = par2_stream("0.dat", 1000);
    store_nzb(
        &db.pool,
        1,
        GUID,
        &nzb(&[
            ("\"data.rar\" yEnc (1/1)", &[(1, 45_000_000, "rar1@x")]),
            (
                "\"x.par2\" yEnc (1/1)",
                &[(1, data.len() as i64 + 200, "idx@x")],
            ),
        ]),
    )
    .await;
    let server = serve(vec![("idx@x", Reply::Body(article(&data, "x.par2")))]).await;
    let mut checker = checker(&db.pool, redis, server.port, Arc::new(NoSearch), &prefix()).await;

    let (outcome, _) = checker.check(GUID, 1, 5, 1, None, None).await;

    assert_eq!(outcome, Par2Outcome::NoMatch);
    let (searchname, proc_par2, renamed, _) = status(&db.pool, 1).await;
    assert_eq!(
        (searchname.as_str(), proc_par2, renamed),
        (OBFUSCATED, 1, 0)
    );
    assert_eq!(
        count(
            &db.pool,
            "SELECT COUNT(*) FROM par2_rename_progress WHERE releases_id = ?",
            1
        )
        .await,
        0
    );
    db.drop_database().await.expect("drop");
}

#[tokio::test]
async fn a_held_lock_defers_and_a_status_other_than_one_is_not_checked() {
    let Some(db) = TestDb::create().await.expect("db") else {
        return;
    };
    let Some(redis) = redis().await else {
        return;
    };
    insert_group(&db.pool, 5, "alt.test").await;
    insert_release(&db.pool, 1, GUID, OBFUSCATED, 1000, 5).await;
    let server = serve(Vec::new()).await;
    let key = prefix();
    let mut checker = checker(
        &db.pool,
        redis.clone(),
        server.port,
        Arc::new(NoSearch),
        &key,
    )
    .await;
    assert_eq!(
        checker.check(GUID, 1, 5, 0, None, None).await.0,
        Par2Outcome::Deferred
    );
    let other = nntmux_namefix::par2_lock::RedisLock::new(redis, format!("{key}rename:par2:1"), 60);
    assert!(other.acquire().await.expect("lock"));
    assert_eq!(
        checker.check(GUID, 1, 5, 1, None, None).await.0,
        Par2Outcome::Deferred
    );
    assert_eq!(server.bodies(), 0);
    // A release whose identity differs from the caller's is left alone.
    other.release().await.expect("release");
    assert_eq!(
        checker
            .check("f".repeat(40).as_str(), 1, 5, 1, None, None)
            .await
            .0,
        Par2Outcome::Deferred
    );
    db.drop_database().await.expect("drop");
}

#[tokio::test]
async fn a_lost_claim_applies_nothing() {
    let Some(db) = TestDb::create().await.expect("db") else {
        return;
    };
    insert_group(&db.pool, 5, "alt.test").await;
    insert_release(&db.pool, 1, GUID, OBFUSCATED, 50_000_000, 5).await;
    let mut inventory = PacketAccumulator::new(262_144).unwrap();
    inventory
        .append(&par2_stream(&format!("{SCENE}.mkv"), 1000))
        .unwrap();
    let categorizer = Categorizer::load(&db.pool).await.expect("categorizer");
    let search: Arc<dyn ReleaseSearch> = Arc::new(NoSearch);
    let updater = ReleaseUpdater::new(db.pool.clone(), categorizer, search.clone(), fixer_cleaner);
    let mut fixer = NameFixer::new(updater, FixNamesConfig::from_env());
    let claim = Arc::new(Switch::new(false));
    let guard: Arc<dyn Guard> = claim.clone();

    let lost = apply_inventory(
        Context {
            fixer: &mut fixer,
            search: &search,
            add_par2: true,
            guard: &guard,
        },
        &inventory,
        1,
        5,
        true,
        None,
    )
    .await
    .expect("apply");
    assert_eq!(lost.outcome, Par2Outcome::Deferred);
    assert_eq!(
        count(
            &db.pool,
            "SELECT COUNT(*) FROM par2_descriptor_evidence WHERE releases_id = ?",
            1
        )
        .await,
        0
    );

    // Once the claim holds, the same inventory renames the release.
    claim.set(true);
    let renamed = apply_inventory(
        Context {
            fixer: &mut fixer,
            search: &search,
            add_par2: false,
            guard: &guard,
        },
        &inventory,
        1,
        5,
        true,
        None,
    )
    .await
    .expect("apply");
    assert_eq!(renamed.outcome, Par2Outcome::Renamed);
    assert_eq!(status(&db.pool, 1).await.0, SCENE);
    db.drop_database().await.expect("drop");
}

#[tokio::test]
async fn the_snapshot_a_caller_probed_under_must_still_hold() {
    let Some(db) = TestDb::create().await.expect("db") else {
        return;
    };
    insert_group(&db.pool, 5, "alt.test").await;
    insert_release(&db.pool, 1, GUID, OBFUSCATED, 50_000_000, 5).await;
    let mut inventory = PacketAccumulator::new(262_144).unwrap();
    inventory
        .append(&par2_stream(&format!("{SCENE}.mkv"), 1000))
        .unwrap();
    let categorizer = Categorizer::load(&db.pool).await.expect("categorizer");
    let search: Arc<dyn ReleaseSearch> = Arc::new(NoSearch);
    let updater = ReleaseUpdater::new(db.pool.clone(), categorizer, search.clone(), fixer_cleaner);
    let mut fixer = NameFixer::new(updater, FixNamesConfig::from_env());
    let guard: Arc<dyn Guard> = Arc::new(Unguarded);
    let stale = Expected {
        snapshot: Snapshot {
            id: 1,
            guid: GUID.into(),
            name: Some("renamed meanwhile".into()),
            searchname: Some(OBFUSCATED.into()),
        },
        max_payload_bytes: None,
    };
    let applied = apply_inventory(
        Context {
            fixer: &mut fixer,
            search: &search,
            add_par2: true,
            guard: &guard,
        },
        &inventory,
        1,
        5,
        true,
        Some(&stale),
    )
    .await
    .expect("apply");
    assert_eq!(applied.outcome, Par2Outcome::Deferred);
    // A payload bound smaller than the set rules the whole set out.
    let small = Expected {
        snapshot: Snapshot {
            id: 1,
            guid: GUID.into(),
            name: Some(OBFUSCATED.into()),
            searchname: Some(OBFUSCATED.into()),
        },
        max_payload_bytes: Some(10),
    };
    let applied = apply_inventory(
        Context {
            fixer: &mut fixer,
            search: &search,
            add_par2: true,
            guard: &guard,
        },
        &inventory,
        1,
        5,
        true,
        Some(&small),
    )
    .await
    .expect("apply");
    assert_eq!(applied.outcome, Par2Outcome::NoMatch);
    assert_eq!(status(&db.pool, 1).await.0, OBFUSCATED);
    db.drop_database().await.expect("drop");
}

#[tokio::test]
async fn progress_and_checkpoints_keep_their_rules() {
    let Some(db) = TestDb::create().await.expect("db") else {
        return;
    };
    insert_group(&db.pool, 5, "alt.test").await;
    insert_release(&db.pool, 1, GUID, OBFUSCATED, 1000, 5).await;
    let manifest = "a".repeat(64);
    let candidate = "b".repeat(64);
    let snapshot = Snapshot {
        id: 1,
        guid: GUID.into(),
        name: Some(OBFUSCATED.into()),
        searchname: Some(OBFUSCATED.into()),
    };

    assert!(
        par2_progress::save(&db.pool, 1, &manifest, 2, &[0])
            .await
            .unwrap()
    );
    let stored = par2_progress::get(&db.pool, 1).await.unwrap().unwrap();
    assert_eq!((stored.offset, stored.retry_indexes.clone()), (2, vec![0]));

    // A checkpoint under the same manifest survives; under another it takes the progress with it.
    let mut inventory = PacketAccumulator::new(1 << 20).unwrap();
    inventory.append(&par2_stream("a.bin", 10)).unwrap();
    let identity = ArticleIdentity {
        name_base64: "YS5wYXIy".into(),
        size: 100,
        part: Some(1),
    };
    assert!(
        checkpoints::save(
            &db.pool,
            &save_request(
                &snapshot,
                &manifest,
                &candidate,
                1,
                Some(&inventory),
                None,
                Some(&identity),
                1 << 20
            ),
            &Unguarded
        )
        .await
        .unwrap()
    );
    let loaded = checkpoints::load(&db.pool, 1, &manifest, &candidate, 4, 1 << 20)
        .await
        .unwrap()
        .unwrap();
    assert_eq!((loaded.next_segment, loaded.decoded_offset), (1, 50));
    assert_eq!(
        loaded.accumulator.unwrap().state_json(),
        inventory.state_json()
    );
    assert_eq!(loaded.identity, Some(identity.clone()));
    assert!(
        checkpoints::load(&db.pool, 1, &"c".repeat(64), &candidate, 4, 1 << 20)
            .await
            .unwrap()
            .is_none()
    );

    // A state past the release ceiling becomes a blocked tombstone, and a block stays put.
    assert!(
        checkpoints::save(
            &db.pool,
            &save_request(
                &snapshot,
                &manifest,
                &candidate,
                2,
                Some(&inventory),
                None,
                Some(&identity),
                100
            ),
            &Unguarded
        )
        .await
        .unwrap()
    );
    let tomb = checkpoints::load(&db.pool, 1, &manifest, &candidate, 4, 100)
        .await
        .unwrap()
        .unwrap();
    assert_eq!(tomb.blocked_reason.as_deref(), Some("state_limit"));
    assert!(tomb.accumulator.is_none() && tomb.identity.is_none());
    assert!(
        checkpoints::save(
            &db.pool,
            &save_request(
                &snapshot,
                &manifest,
                &candidate,
                3,
                Some(&inventory),
                None,
                None,
                1 << 20
            ),
            &Unguarded
        )
        .await
        .unwrap()
    );
    let kept = checkpoints::load(&db.pool, 1, &manifest, &candidate, 4, 1 << 20)
        .await
        .unwrap()
        .unwrap();
    assert_eq!(
        (kept.next_segment, kept.blocked_reason.as_deref()),
        (2, Some("state_limit"))
    );

    // A corrupt row is refused on load and can be quarantined.
    sqlx::query("UPDATE par2_rename_candidate_progress SET stored_bytes = 7 WHERE releases_id = 1")
        .execute(&db.pool)
        .await
        .unwrap();
    assert!(
        checkpoints::load(&db.pool, 1, &manifest, &candidate, 4, 1 << 20)
            .await
            .is_err()
    );
    assert!(
        checkpoints::block_corrupt_candidate(
            &db.pool,
            &snapshot,
            &manifest,
            &candidate,
            4,
            1 << 20,
            &Unguarded
        )
        .await
        .unwrap()
    );
    let quarantined = checkpoints::load(&db.pool, 1, &manifest, &candidate, 4, 1 << 20)
        .await
        .unwrap()
        .unwrap();
    assert_eq!(
        quarantined.blocked_reason.as_deref(),
        Some("malformed_state")
    );

    // A lost claim writes nothing, and deleting works only for the owner.
    let lost = Switch::new(false);
    assert!(
        !checkpoints::delete_candidate(&db.pool, &snapshot, &manifest, &candidate, &lost)
            .await
            .unwrap()
    );
    assert!(
        checkpoints::delete_candidate(&db.pool, &snapshot, &manifest, &candidate, &Unguarded)
            .await
            .unwrap()
    );
    assert!(
        checkpoints::load(&db.pool, 1, &manifest, &candidate, 4, 1 << 20)
            .await
            .unwrap()
            .is_none()
    );

    // A finished release drops its progress instead of recording more.
    sqlx::query("UPDATE releases SET isrenamed = 1 WHERE id = 1")
        .execute(&db.pool)
        .await
        .unwrap();
    assert!(
        !par2_progress::save(&db.pool, 1, &manifest, 3, &[])
            .await
            .unwrap()
    );
    assert!(par2_progress::get(&db.pool, 1).await.unwrap().is_none());
    db.drop_database().await.expect("drop");
}

#[tokio::test]
async fn the_standard_batch_probe_fails_retryably_when_it_cannot_connect() {
    let Some(db) = TestDb::create().await.expect("db") else {
        return;
    };
    let Some(redis) = redis().await else {
        return;
    };
    insert_group(&db.pool, 5, "alt.test").await;
    insert_release(&db.pool, 1, GUID, OBFUSCATED, 1000, 5).await;
    // Nothing listens on port 1.
    let checker = checker(&db.pool, redis, 1, Arc::new(NoSearch), &prefix()).await;
    let mut probe = BatchProbe::new(checker);
    let release = Release {
        id: 1,
        guid: Some(GUID.into()),
        groups_id: 5,
        ..Release::default()
    };
    assert_eq!(probe.probe(&release).await.unwrap(), Par2Outcome::Retryable);
    assert_eq!(status(&db.pool, 1).await.1, 0);
    db.drop_database().await.expect("drop");
}

#[tokio::test]
async fn the_standard_batch_renames_through_the_probe_and_marks_only_a_no_match() {
    let Some(db) = TestDb::create().await.expect("db") else {
        return;
    };
    let Some(redis) = redis().await else {
        return;
    };
    insert_group(&db.pool, 5, "alt.test").await;
    insert_release(&db.pool, 1, GUID, OBFUSCATED, 50_000_000, 5).await;
    let nothing = "8".repeat(40);
    insert_release(
        &db.pool,
        2,
        &nothing,
        "eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee",
        50_000_000,
        5,
    )
    .await;
    let missing = "7".repeat(40);
    insert_release(
        &db.pool,
        3,
        &missing,
        "dddddddddddddddddddddddddddddddd",
        50_000_000,
        5,
    )
    .await;
    let good = par2_stream(&format!("{SCENE}.mkv"), 40_000_000);
    let useless = par2_stream("0.dat", 1000);
    for (id, guid, article_id, length) in [
        (1, GUID, "good@x", good.len()),
        (2, nothing.as_str(), "useless@x", useless.len()),
        (3, missing.as_str(), "missing@x", 5000),
    ] {
        store_nzb(
            &db.pool,
            id,
            guid,
            &nzb(&[
                ("\"data.rar\" yEnc (1/1)", &[(1, 45_000_000, "rar@x")]),
                (
                    "\"x.par2\" yEnc (1/1)",
                    &[(1, length as i64 + 200, article_id)],
                ),
            ]),
        )
        .await;
    }
    let server = serve(vec![
        ("good@x", Reply::Body(article(&good, "x.par2"))),
        ("useless@x", Reply::Body(article(&useless, "x.par2"))),
        ("missing@x", Reply::Missing),
    ])
    .await;
    let key = prefix();
    let probe_checker = checker(&db.pool, redis, server.port, Arc::new(NoSearch), &key).await;
    let mut probe = BatchProbe::new(probe_checker);
    let categorizer = Categorizer::load(&db.pool).await.expect("categorizer");
    let updater = ReleaseUpdater::new(
        db.pool.clone(),
        categorizer,
        Arc::new(NoSearch),
        fixer_cleaner,
    );
    let mut fixer = NameFixer::new(updater, FixNamesConfig::from_env());
    let releases: Vec<Release> = [
        (1, GUID, OBFUSCATED),
        (2, nothing.as_str(), "eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee"),
        (3, missing.as_str(), "dddddddddddddddddddddddddddddddd"),
    ]
    .into_iter()
    .map(|(id, guid, name)| Release {
        id,
        guid: Some(guid.into()),
        name: Some(name.into()),
        searchname: Some(name.into()),
        groups_id: 5,
        categories_id: cat::OTHER_MISC,
        relsize: 50_000_000,
        proc_nfo: 1,
        proc_files: 1,
        proc_uid: 1,
        proc_srr: 1,
        proc_hash16k: 1,
        proc_crc32: 1,
        proc_par2: 0,
        ..Release::default()
    })
    .collect();

    let (checked, fixed) = fixer
        .process_standard_batch(releases, false, &mut probe)
        .await
        .expect("batch");

    assert_eq!((checked, fixed), (3, 1));
    assert_eq!(status(&db.pool, 1).await.0, SCENE);
    assert_eq!(
        status(&db.pool, 2).await.1,
        1,
        "a complete set with no name is processed"
    );
    assert_eq!(
        status(&db.pool, 3).await.1,
        0,
        "a missing article never marks the release"
    );
    db.drop_database().await.expect("drop");
}

#[tokio::test]
async fn the_sweep_resumes_from_its_cursor_and_renames() {
    let Some(db) = TestDb::create().await.expect("db") else {
        return;
    };
    let Some(redis) = redis().await else {
        return;
    };
    insert_group(&db.pool, 5, "alt.test").await;
    insert_release(&db.pool, 1, GUID, OBFUSCATED, 50_000_000, 5).await;
    let other = "9".repeat(40);
    insert_release(
        &db.pool,
        2,
        &other,
        "ffffffffffffffffffffffffffffffff",
        50_000_000,
        5,
    )
    .await;
    let data = par2_stream(&format!("{SCENE}.mkv"), 40_000_000);
    let len = data.len() as i64 + 200;
    for (id, guid, msg) in [(1, GUID, "idx1@x"), (2, other.as_str(), "idx2@x")] {
        store_nzb(
            &db.pool,
            id,
            guid,
            &nzb(&[
                ("\"data.rar\" yEnc (1/1)", &[(1, 45_000_000, "rar@x")]),
                ("\"x.par2\" yEnc (1/1)", &[(1, len, msg)]),
            ]),
        )
        .await;
    }
    let server = serve(vec![
        ("idx1@x", Reply::Body(article(&data, "x.par2"))),
        ("idx2@x", Reply::Missing),
    ])
    .await;
    let key = prefix();
    let mut checker = checker(
        &db.pool,
        redis.clone(),
        server.port,
        Arc::new(NoSearch),
        &key,
    )
    .await;
    checker.config.par2_max_releases = 10;

    fix_names_with_par2(&mut checker, 2, true, 1, true)
        .await
        .expect("sweep");

    assert_eq!(status(&db.pool, 1).await.0, SCENE);
    // The second release failed retryably: still pending, with its retry recorded.
    let (_, proc_par2, renamed, _) = status(&db.pool, 2).await;
    assert_eq!((proc_par2, renamed), (0, 0));
    assert_eq!(checker.fixer.updater.fixed, 1);
    assert_eq!(checker.fixer.updater.checked, 2);
    let mut conn = redis.clone();
    let cursor: Option<String> =
        redis::AsyncCommands::get(&mut conn, format!("{key}rename:par2:sweep:2:1"))
            .await
            .unwrap();
    assert!(cursor.expect("cursor").contains("\"after_id\":2"));
    // Without --update and --set-status nothing is downloaded.
    let before = server.bodies();
    fix_names_with_par2(&mut checker, 2, false, 1, true)
        .await
        .expect("sweep");
    assert_eq!(server.bodies(), before);
    db.drop_database().await.expect("drop");
}

#[tokio::test]
async fn the_chain_runs_when_enabled_with_work_and_then_rests() {
    let Some(db) = TestDb::create().await.expect("db") else {
        return;
    };
    let Some(redis) = redis().await else {
        return;
    };
    let ctx = nntmux_runtime::Ctx {
        config: Arc::new(nntmux_core::Config {
            database: nntmux_core::DatabaseConfig {
                host: String::new(),
                port: 0,
                database: String::new(),
                username: String::new(),
                password: String::new(),
                ssl_ca: None,
                max_connections: 1,
            },
            redis_url: String::new(),
            manticore: nntmux_core::ManticoreConfig {
                host: String::new(),
                sql_port: 0,
                http_port: 0,
            },
            health_addr: "127.0.0.1:0".parse().unwrap(),
            log_filter: String::new(),
            log_json: false,
        }),
        db: db.pool.clone(),
        redis,
    };
    let config = FixNamesConfig::from_env();
    let key = prefix();
    // The setting seeds as disabled.
    assert!(
        fixnames_chain::claim(&ctx, &config, &key)
            .await
            .unwrap()
            .is_none()
    );
    sqlx::query("UPDATE tmux_configurations SET fix_names_enabled = 1, fix_timer = 300")
        .execute(&db.pool)
        .await
        .unwrap();
    // Enabled but with nothing pending, and nothing to backlog (the interval claim is taken
    // here, so the even levels run once).
    let first = fixnames_chain::claim(&ctx, &config, &key)
        .await
        .unwrap()
        .expect("backlog pass");
    assert_eq!(first.levels, vec![4, 6, 10, 12, 14, 16, 18, 20, 8]);
    // A second claim while the first runs, or while it rests, finds nothing.
    assert!(
        fixnames_chain::claim(&ctx, &config, &key)
            .await
            .unwrap()
            .is_none()
    );
    first.finish().await;
    assert!(
        fixnames_chain::claim(&ctx, &config, &key)
            .await
            .unwrap()
            .is_none()
    );
    db.drop_database().await.expect("drop");
}

// `Claim` is implemented for the types used above; this keeps the bound visible.
fn _claims(_: &dyn Claim) {}
