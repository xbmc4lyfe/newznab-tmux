//! `update_release` against a real database and Manticore. Skips unless `TEST_DATABASE_URL` and
//! `TEST_MANTICORE_HTTP` are set.

use nntmux_search::{
    ReleaseSearchQuery, SearchClient, SearchConfig, config::IndexNames, create_table_sql,
};
use nntmux_testkit::TestDb;
use sqlx::MySqlPool;

async fn seed_release(pool: &MySqlPool, id: i64) {
    sqlx::query(
        "INSERT INTO usenet_groups (id, name) VALUES (99977, 'alt.binaries.search.golden') \
         ON DUPLICATE KEY UPDATE name = name",
    )
    .execute(pool)
    .await
    .unwrap();
    sqlx::query(
        "INSERT INTO releases (id, name, searchname, totalpart, groups_id, size, postdate, adddate, \
         guid, leftguid, fromname, categories_id, passwordstatus, nzbstatus, tv_episodes_id, videos_id, movieinfo_id, imdbid) \
         VALUES (?, 'raw.name', 'Some.Show-S01_E02', 12, 99977, 1234, '2024-03-01 10:00:00', '2024-03-01 11:00:00', \
         ?, 'a', 'poster@example.com', 2040, 0, 1, 0, 0, NULL, '0133093')",
    )
    .bind(id)
    .bind(format!("{id:0>40}"))
    .execute(pool)
    .await
    .unwrap();
    sqlx::query("INSERT INTO release_files (releases_id, name) VALUES (?, 'a.mkv'), (?, 'b.mkv')")
        .bind(id)
        .bind(id)
        .execute(pool)
        .await
        .unwrap();
    sqlx::query("INSERT INTO video_data (releases_id, containerformat, videocodec, videowidth, videoheight) VALUES (?, 'Matroska', 'h264', 1920, 1080)")
        .bind(id)
        .execute(pool)
        .await
        .unwrap();
    sqlx::query("INSERT INTO audio_data (releases_id, audioid, audioformat, audiolanguage) VALUES (?, 1, 'AAC', 'en'), (?, 2, 'DTS', 'de')")
        .bind(id)
        .bind(id)
        .execute(pool)
        .await
        .unwrap();
}

struct Live {
    db: TestDb,
    client: SearchClient,
    table: String,
}

async fn live() -> Option<Live> {
    let Ok(address) = std::env::var("TEST_MANTICORE_HTTP") else {
        eprintln!("TEST_MANTICORE_HTTP is unset, skipping");
        return None;
    };
    let db = TestDb::create().await.unwrap()?;
    let prefix = format!("t{:016x}_", rand::random::<u64>());
    let mut config = SearchConfig::new(format!("http://{address}"));
    config.indexes = IndexNames::prefixed(&prefix);
    let table = config.indexes.table("releases").to_owned();
    let client = SearchClient::new(config, None).unwrap();
    client
        .sql(&create_table_sql(&table, "releases"))
        .await
        .unwrap();
    Some(Live { db, client, table })
}

fn all_releases() -> ReleaseSearchQuery {
    ReleaseSearchQuery {
        include_documents: true,
        ..ReleaseSearchQuery::default()
    }
}

#[tokio::test]
async fn update_release_writes_the_projection_and_removes_gone_rows() {
    let Some(live) = live().await else {
        return;
    };
    seed_release(&live.db.pool, 501).await;
    assert!(live.client.update_release(&live.db.pool, 501).await);

    let page = live.client.search_releases(&all_releases()).await.unwrap();
    assert_eq!(page.ids, [501]);
    let document = &page.documents[0];
    assert_eq!(document.searchname, "Some.Show-S01_E02");
    assert_eq!(document.plainsearchname, "Some Show S01 E02");
    assert_eq!(document.group_name, "alt.binaries.search.golden");
    assert_eq!(document.postdate_ts, 1_709_287_200);
    assert_eq!(document.size, 1234);
    assert_eq!(document.filename, "a.mkv b.mkv");
    assert_eq!(document.imdbid, "0133093");
    assert_eq!(document.reid, 501);
    assert_eq!(document.has_media_info, 1);
    assert_eq!(document.media_video_width, 1920);
    assert_eq!(document.media_audio_format, "AAC DTS");
    assert_eq!(document.media_audio_language, "en de");
    assert_eq!(document.category_name, "Movies > HD");
    assert_eq!(document.parentid, 2000);

    // A retry that succeeds resolves an earlier failure.
    sqlx::query("INSERT INTO search_index_failures (release_id, operation, attempts, next_attempt_at) VALUES (501, 'upsert', 3, NOW())")
        .execute(&live.db.pool)
        .await
        .unwrap();
    assert!(live.client.update_release(&live.db.pool, 501).await);
    let resolved: (Option<chrono::NaiveDateTime>, Option<chrono::NaiveDateTime>) = sqlx::query_as(
        "SELECT resolved_at, next_attempt_at FROM search_index_failures WHERE release_id = 501",
    )
    .fetch_one(&live.db.pool)
    .await
    .unwrap();
    assert!(resolved.0.is_some());
    assert!(resolved.1.is_none());

    sqlx::query("DELETE FROM releases WHERE id = 501")
        .execute(&live.db.pool)
        .await
        .unwrap();
    assert!(live.client.update_release(&live.db.pool, 501).await);
    let page = live.client.search_releases(&all_releases()).await.unwrap();
    assert!(page.ids.is_empty());

    assert!(!live.client.update_release(&live.db.pool, 0).await);

    live.client
        .sql(&format!("DROP TABLE {}", live.table))
        .await
        .unwrap();
    live.db.drop_database().await.unwrap();
}

#[tokio::test]
async fn a_failed_write_records_a_retry_and_leaves_a_lease_alone() {
    let Some(db) = TestDb::create().await.unwrap() else {
        return;
    };
    let mut config = SearchConfig::new("http://127.0.0.1:9");
    config.retry_delay_ms = 0;
    let client = SearchClient::new(config, None).unwrap();
    seed_release(&db.pool, 601).await;
    seed_release(&db.pool, 602).await;

    assert!(!client.update_release(&db.pool, 601).await);
    let row: (String, u32, Option<String>, bool) = sqlx::query_as(
        "SELECT operation, attempts, last_error, next_attempt_at > UTC_TIMESTAMP() - INTERVAL 1 MINUTE \
         FROM search_index_failures WHERE release_id = 601",
    )
    .fetch_one(&db.pool)
    .await
    .unwrap();
    assert_eq!(row.0, "upsert");
    assert_eq!(row.1, 1);
    assert_eq!(row.2.as_deref(), Some("insertRelease"));
    assert!(row.3);

    assert!(!client.update_release(&db.pool, 601).await);
    let attempts: u32 =
        sqlx::query_scalar("SELECT attempts FROM search_index_failures WHERE release_id = 601")
            .fetch_one(&db.pool)
            .await
            .unwrap();
    assert_eq!(attempts, 2);

    // A release pass's lease keeps its token and operation, and only counts the attempt.
    sqlx::query("INSERT INTO search_index_failures (release_id, operation, attempts, last_error, next_attempt_at) VALUES (602, 'deferred', 0, 'deferred:token', NOW())")
        .execute(&db.pool)
        .await
        .unwrap();
    assert!(!client.update_release(&db.pool, 602).await);
    let lease: (String, u32, Option<String>) = sqlx::query_as(
        "SELECT operation, attempts, last_error FROM search_index_failures WHERE release_id = 602",
    )
    .fetch_one(&db.pool)
    .await
    .unwrap();
    assert_eq!(
        lease,
        ("deferred".to_owned(), 1, Some("deferred:token".to_owned()))
    );

    // A missing row that can't be removed from the index records a delete retry.
    assert!(!client.update_release(&db.pool, 999).await);
    let operation: String =
        sqlx::query_scalar("SELECT operation FROM search_index_failures WHERE release_id = 999")
            .fetch_one(&db.pool)
            .await
            .unwrap();
    assert_eq!(operation, "delete");

    db.drop_database().await.unwrap();
}
