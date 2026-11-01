//! Header storage against a real database. Skips when TEST_DATABASE_URL is unset.

use nntmux_core::pcre::Pcre;
use nntmux_ingest::clean::CollectionRegex;
use nntmux_ingest::groups::{self, Group};
use nntmux_ingest::header::{Overview, match_subject};
use nntmux_ingest::missed::MissedParts;
use nntmux_ingest::store::{NoHooks, ParsedHeader, StoreLimits, store};
use nntmux_testkit::TestDb;
use sqlx::MySqlPool;

const LIMITS: StoreLimits = StoreLimits {
    header_chunk_size: 500,
    sql_chunk_size: 500,
    verification_required: true,
};

fn header(number: u64, subject: &str, bytes: i64) -> ParsedHeader {
    ParsedHeader {
        overview: Overview {
            number,
            subject: subject.as_bytes().to_vec(),
            from: b"poster@example.com".to_vec(),
            date: b"26 Jun 2014 13:08:22 GMT".to_vec(),
            message_id: format!("<{number}@example>").into_bytes(),
            bytes,
            xref: b"news alt.binaries.misc:1 alt.binaries.teevee:2".to_vec(),
        },
        subject: match_subject(subject.as_bytes()).unwrap(),
    }
}

async fn group(pool: &MySqlPool) -> Group {
    sqlx::query("INSERT INTO usenet_groups (name, active) VALUES ('alt.binaries.ingest.test', 1)")
        .execute(pool)
        .await
        .unwrap();
    groups::by_name(pool, "alt.binaries.ingest.test")
        .await
        .unwrap()
        .unwrap()
}

fn no_regexes() -> Vec<CollectionRegex> {
    Vec::new()
}

async fn count(pool: &MySqlPool, table: &str) -> i64 {
    sqlx::query_scalar(sqlx::AssertSqlSafe(format!("SELECT COUNT(*) FROM {table}")))
        .fetch_one(pool)
        .await
        .unwrap()
}

#[tokio::test]
async fn stores_a_complete_collection_and_is_idempotent() {
    let Some(db) = TestDb::create().await.unwrap() else {
        return;
    };
    let group = group(&db.pool).await;
    let headers: Vec<ParsedHeader> = [1, 2]
        .into_iter()
        .flat_map(|file| {
            [1, 2].into_iter().map(move |part| {
                header(
                    file * 10 + part,
                    &format!("Show.S01E01 [{file:02}/02] - \"f{file}.rar\" yEnc ({part}/2)"),
                    100,
                )
            })
        })
        .collect();

    for _ in 0..2 {
        let failed = store(&db.pool, &NoHooks, LIMITS, &group, &no_regexes(), &headers)
            .await
            .unwrap();
        assert!(failed.is_empty(), "{failed:?}");
    }

    assert_eq!(count(&db.pool, "collections").await, 1);
    assert_eq!(count(&db.pool, "binaries").await, 2);
    assert_eq!(count(&db.pool, "parts").await, 4);
    let (filesize, filecheck, totalfiles, seen): (u64, i8, u32, Option<chrono::NaiveDateTime>) =
        sqlx::query_as("SELECT filesize, filecheck, totalfiles, last_seen_at FROM collections")
            .fetch_one(&db.pool)
            .await
            .unwrap();
    assert_eq!((filesize, filecheck, totalfiles), (400, 3, 2));
    assert!(seen.is_some());
    let rows: Vec<(u32, u32, i8, u64, u32)> = sqlx::query_as(
        "SELECT totalparts, currentparts, partcheck, partsize, filenumber FROM binaries ORDER BY filenumber",
    )
    .fetch_all(&db.pool)
    .await
    .unwrap();
    assert_eq!(rows, vec![(2, 2, 1, 200, 1), (2, 2, 1, 200, 2)]);
    let cross: Vec<String> =
        sqlx::query_scalar("SELECT group_name FROM collection_groups ORDER BY group_name")
            .fetch_all(&db.pool)
            .await
            .unwrap();
    assert_eq!(cross, ["alt.binaries.misc", "alt.binaries.teevee"]);
    let xref: String = sqlx::query_scalar("SELECT xref FROM collections")
        .fetch_one(&db.pool)
        .await
        .unwrap();
    assert_eq!(xref, "alt.binaries.misc:1 alt.binaries.teevee:2");
    let date: String =
        sqlx::query_scalar("SELECT CAST(UNIX_TIMESTAMP(date) AS CHAR) FROM collections")
            .fetch_one(&db.pool)
            .await
            .unwrap();
    assert_eq!(date, "1403788102");
    db.drop_database().await.unwrap();
}

#[tokio::test]
async fn partial_collections_stay_unsized_and_late_parts_complete_them() {
    let Some(db) = TestDb::create().await.unwrap() else {
        return;
    };
    let group = group(&db.pool).await;
    let subject = |part: u32| format!("Movie [01/01] - \"m.mkv\" yEnc ({part}/2)");
    store(
        &db.pool,
        &NoHooks,
        LIMITS,
        &group,
        &no_regexes(),
        &[header(1, &subject(1), 10)],
    )
    .await
    .unwrap();
    let filecheck = || async {
        sqlx::query_scalar::<_, i8>("SELECT filecheck FROM collections")
            .fetch_one(&db.pool)
            .await
            .unwrap()
    };
    assert_eq!(filecheck().await, 0);
    store(
        &db.pool,
        &NoHooks,
        LIMITS,
        &group,
        &no_regexes(),
        &[header(2, &subject(2), 10)],
    )
    .await
    .unwrap();
    assert_eq!(filecheck().await, 3);
    db.drop_database().await.unwrap();
}

#[tokio::test]
async fn keeps_the_preferred_duplicate_part() {
    let Some(db) = TestDb::create().await.unwrap() else {
        return;
    };
    let group = group(&db.pool).await;
    let subject = "Dup [01/01] - \"d.rar\" yEnc (1/2)";
    let headers = [
        header(5, subject, 10),
        header(6, subject, 30),
        header(7, subject, 30),
    ];
    let failed = store(&db.pool, &NoHooks, LIMITS, &group, &no_regexes(), &headers)
        .await
        .unwrap();
    assert!(failed.is_empty());
    let (number, size): (u64, u32) = sqlx::query_as("SELECT number, size FROM parts")
        .fetch_one(&db.pool)
        .await
        .unwrap();
    assert_eq!((number, size), (6, 30));
    db.drop_database().await.unwrap();
}

#[tokio::test]
async fn rejects_invalid_headers_without_losing_the_chunk() {
    let Some(db) = TestDb::create().await.unwrap() else {
        return;
    };
    let group = group(&db.pool).await;
    let mut bad_id = header(1, "A [01/01] - \"a.rar\" yEnc (1/2)", 1);
    bad_id.overview.message_id = b"\xe9bad".to_vec();
    let zero = header(2, "A [01/01] - \"a.rar\" yEnc (0/2)", 1);
    let good = header(3, "A [01/01] - \"a.rar\" yEnc (2/2)", 1);
    let failed = store(
        &db.pool,
        &NoHooks,
        LIMITS,
        &group,
        &no_regexes(),
        &[bad_id, zero, good],
    )
    .await
    .unwrap();
    assert_eq!(failed, [1, 2]);
    assert_eq!(count(&db.pool, "parts").await, 1);
    db.drop_database().await.unwrap();
}

#[tokio::test]
async fn names_collections_with_stored_regexes() {
    let Some(db) = TestDb::create().await.unwrap() else {
        return;
    };
    let group = group(&db.pool).await;
    let regexes = vec![CollectionRegex {
        id: 77,
        regex: Pcre::new(r"/^(?P<match0>Grouped)/").unwrap(),
    }];
    let headers = [
        header(1, "Grouped one yEnc (1/1)", 1),
        header(2, "Grouped two yEnc (1/1)", 1),
    ];
    store(&db.pool, &NoHooks, LIMITS, &group, &regexes, &headers)
        .await
        .unwrap();
    let rows: Vec<(i32, String)> =
        sqlx::query_as("SELECT collection_regexes_id, subject FROM collections")
            .fetch_all(&db.pool)
            .await
            .unwrap();
    assert_eq!(rows, vec![(77, "Grouped one yEnc".to_owned())]);
    assert_eq!(count(&db.pool, "binaries").await, 2);
    db.drop_database().await.unwrap();
}

#[tokio::test]
async fn parallel_workers_on_shared_collections_lose_nothing() {
    let Some(db) = TestDb::create().await.unwrap() else {
        return;
    };
    let group = group(&db.pool).await;
    let mut tasks = Vec::new();
    for worker in 0..4_u64 {
        let pool = db.pool.clone();
        let group = group.clone();
        tasks.push(tokio::spawn(async move {
            let headers: Vec<ParsedHeader> = (0..200_u64)
                .map(|i| {
                    let file = i % 20 + 1;
                    let part = i / 20 + 1 + worker * 10;
                    header(
                        worker * 1000 + i,
                        &format!("Shared [{file:02}/20] - \"s{file}.rar\" yEnc ({part}/50)"),
                        5,
                    )
                })
                .collect();
            store(&pool, &NoHooks, LIMITS, &group, &no_regexes(), &headers).await
        }));
    }
    for task in tasks {
        assert_eq!(task.await.unwrap().unwrap(), Vec::<u64>::new());
    }
    assert_eq!(count(&db.pool, "collections").await, 1);
    assert_eq!(count(&db.pool, "binaries").await, 20);
    assert_eq!(count(&db.pool, "parts").await, 800);
    let (current, size): (u64, u64) = sqlx::query_as(
        "SELECT CAST(SUM(currentparts) AS UNSIGNED), CAST(SUM(partsize) AS UNSIGNED) FROM binaries",
    )
    .fetch_one(&db.pool)
    .await
    .unwrap();
    assert_eq!((current, size), (800, 4000));
    db.drop_database().await.unwrap();
}

#[tokio::test]
async fn missed_parts_queue_counts_attempts() {
    let Some(db) = TestDb::create().await.unwrap() else {
        return;
    };
    let pool = &db.pool;
    let group = group(pool).await.id;
    let missed = MissedParts::new(100, 3, 50);
    let attempts = |number: u64| async move {
        sqlx::query_scalar::<_, i8>("SELECT attempts FROM missed_parts WHERE numberid = ?")
            .bind(number)
            .fetch_one(pool)
            .await
            .unwrap()
    };

    // Deferring uses no retries, and doesn't touch retries already used.
    missed.add(pool, &[5, 5, 6], group).await.unwrap();
    missed.defer_range(pool, 5, 200, group).await.unwrap();
    assert_eq!(attempts(5).await, 1);
    assert_eq!(attempts(7).await, 0);
    assert_eq!(count(pool, "missed_parts").await, 196);

    missed.add(pool, &[5], group).await.unwrap();
    assert_eq!(attempts(5).await, 2);

    let pending = missed.pending(pool, group).await.unwrap();
    assert_eq!(pending.len(), 100);
    assert_eq!(pending[..2], [5, 6]);

    missed.increment_range(pool, group, 7, 8).await.unwrap();
    assert_eq!(attempts(7).await, 1);
    missed.increment_up_to(pool, group, 5).await.unwrap();
    assert_eq!(attempts(5).await, 3);
    assert_eq!(missed.pending(pool, group).await.unwrap()[0], 6);

    missed.remove(pool, &[6], group).await;
    missed.remove_exhausted(pool, group).await.unwrap();
    assert_eq!(count(pool, "missed_parts").await, 194);
    db.drop_database().await.unwrap();
}
