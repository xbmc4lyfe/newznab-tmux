use flate2::{Compression, write::GzEncoder};
use nntmux_fingerprint::backfill;
use nntmux_fingerprint::database::{self, Claim};
use nntmux_testkit::TestDb;
use serde_json::json;
use sha2::{Digest, Sha256};
use sqlx::MySqlPool;
use std::{io::Write, sync::atomic::Ordering, time::Duration};

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
async fn claims_fencing_and_global_slots() {
    let Some(db) = TestDb::create().await.unwrap() else {
        return;
    };
    let pool = db.pool.clone();
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
    assert_eq!(
        database::slot(&pool, &current, "integration-test", "a")
            .await
            .unwrap(),
        1
    );
    let mut competing = current.clone();
    competing.token = database::token();
    competing.held_slots = std::sync::Arc::new(std::sync::atomic::AtomicU32::new(0));
    let p = pool.clone();
    let c = competing.clone();
    let waiting =
        tokio::spawn(async move { database::slot(&p, &c, "integration-test", "b").await });
    tokio::time::sleep(Duration::from_millis(350)).await;
    assert!(!waiting.is_finished());
    database::release_slots(&pool, &current).await.unwrap();
    assert_eq!(
        tokio::time::timeout(Duration::from_secs(2), waiting)
            .await
            .unwrap()
            .unwrap()
            .unwrap(),
        1
    );
    database::release_slots(&pool, &competing).await.unwrap();
    current.held_slots.store(0, Ordering::SeqCst);
    // A lost shared connection lease also fences its worker, even with a live job lease.
    database::slot(&pool, &current, "integration-test", "a")
        .await
        .unwrap();
    sqlx::query("UPDATE release_fingerprint_connections SET lease_until=TIMESTAMPADD(SECOND,-1,UTC_TIMESTAMP(6)) WHERE provider='integration-test'").execute(&pool).await.unwrap();
    assert!(!database::renew(&pool, &current).await.unwrap());
    database::release_slots(&pool, &current).await.unwrap();
}

async fn release(pool: &MySqlPool, guid: &str) -> u64 {
    sqlx::query(
        "INSERT INTO releases (name, searchname, groups_id, guid, leftguid, size, adddate, postdate, nzbstatus) \
         VALUES ('n', 's', 1, ?, 'a', 1, UTC_TIMESTAMP(), UTC_TIMESTAMP(), 1)",
    )
    .bind(guid)
    .execute(pool)
    .await
    .unwrap()
    .last_insert_id()
}

async fn stored(pool: &MySqlPool, id: u64, guid: &str) {
    let (body, digest) = source(XML);
    sqlx::query("INSERT INTO release_nzb_contents(guid,releases_id,body,source_digest,generation,decoded_bytes,created_at,updated_at) VALUES(?,?,?,?,?, ?,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6))").bind(guid).bind(id).bind(body).bind(&digest).bind(database::token()).bind(XML.len()as u64).execute(pool).await.unwrap();
}

async fn metadata(pool: &MySqlPool, id: u64, status: &str, json: &str, hours_old: i64) {
    sqlx::query(
        "INSERT INTO release_fingerprint_metadata (releases_id, status, metadata, created_at, updated_at) \
         VALUES (?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP() - INTERVAL ? HOUR)",
    )
    .bind(id)
    .bind(status)
    .bind(json)
    .bind(hours_old)
    .execute(pool)
    .await
    .unwrap();
}

#[tokio::test]
async fn backfill_finds_only_what_is_not_current() {
    let Some(db) = TestDb::create().await.unwrap() else {
        return;
    };
    let pool = &db.pool;
    let current = release(pool, "cur00000").await;
    metadata(
        pool,
        current,
        "complete",
        r#"{"schema_version":1,"format":"compact-v1"}"#,
        0,
    )
    .await;
    let missing = release(pool, "mis00000").await;
    stored(pool, missing, "mis00000").await;
    // The right value in the wrong JSON type isn't current.
    let string_version = release(pool, "str00000").await;
    metadata(
        pool,
        string_version,
        "complete",
        r#"{"schema_version":"1","format":"compact-v1"}"#,
        0,
    )
    .await;
    let old_format = release(pool, "old00000").await;
    metadata(pool, old_format, "complete", r#"{"schema_version":1}"#, 0).await;
    let failed = release(pool, "fai00000").await;
    metadata(
        pool,
        failed,
        "failed",
        r#"{"schema_version":1,"format":"compact-v1"}"#,
        0,
    )
    .await;

    let options = |limit, after_id, dry_run, stale| backfill::Options {
        limit,
        after_id,
        delay_ms: 0,
        dry_run,
        stale,
    };
    let ids = |rows: &[u64]| rows.iter().map(|id| *id as i64).collect::<Vec<_>>();
    assert_eq!(
        backfill::backfill(pool, &options(100, 0, true, false))
            .await
            .unwrap(),
        ids(&[missing, string_version, old_format, failed])
    );
    assert_eq!(
        backfill::backfill(pool, &options(1, missing as i64, true, false))
            .await
            .unwrap(),
        ids(&[string_version])
    );

    // Queueing covers one with a source, and stops with an error at one without.
    assert_eq!(
        backfill::backfill(pool, &options(1, 0, false, false))
            .await
            .unwrap(),
        ids(&[missing])
    );
    let status: String =
        sqlx::query_scalar("SELECT status FROM release_fingerprint_jobs WHERE release_id = ?")
            .bind(missing)
            .fetch_one(pool)
            .await
            .unwrap();
    assert_eq!(status, "pending");
    assert!(
        backfill::backfill(pool, &options(1, missing as i64, false, false))
            .await
            .is_err()
    );

    // The stale sweep: stale for an hour, or processing well past the timeout.
    let recent = release(pool, "rec00000").await;
    metadata(pool, recent, "stale", "{}", 0).await;
    let stale = release(pool, "sta00000").await;
    metadata(pool, stale, "stale", "{}", 2).await;
    let running = release(pool, "run00000").await;
    metadata(pool, running, "processing", "{}", 0).await;
    let stuck = release(pool, "stu00000").await;
    metadata(pool, stuck, "processing", "{}", 2).await;
    assert_eq!(
        backfill::backfill(pool, &options(100, 0, true, true))
            .await
            .unwrap(),
        ids(&[stale, stuck])
    );
}
