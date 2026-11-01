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
    let resolved: bool = sqlx::query_scalar(
        "SELECT resolved_at IS NOT NULL AND next_attempt_at IS NULL \
         FROM search_index_failures WHERE release_id = 501",
    )
    .fetch_one(&live.db.pool)
    .await
    .unwrap();
    assert!(resolved);

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

async fn lease(pool: &MySqlPool, id: i64) -> Option<(String, u32, Option<String>)> {
    sqlx::query_as(
        "SELECT operation, attempts, last_error FROM search_index_failures WHERE release_id = ?",
    )
    .bind(id)
    .fetch_optional(pool)
    .await
    .unwrap()
}

#[tokio::test]
async fn a_deferred_scope_indexes_each_release_once_and_clears_its_leases() {
    let Some(live) = live().await else {
        return;
    };
    let pool = &live.db.pool;
    seed_release(pool, 701).await;
    seed_release(pool, 702).await;
    // A stale row with no release: the scope removes its document.
    let mut scope = live.client.defer_release_updates(pool);
    scope.update_release(701).await;
    scope.update_release(701).await;
    scope.update_release(702).await;
    scope.update_release(703).await;

    let held = lease(pool, 701).await.unwrap();
    assert_eq!(held.0, "deferred");
    assert_eq!(held.1, 0);
    assert!(held.2.unwrap().starts_with("deferred:"));
    let page = live.client.search_releases(&all_releases()).await.unwrap();
    assert!(page.ids.is_empty(), "nothing is written before finish");

    // Another worker's failure on 702 stays on the marker for repair.
    sqlx::query("UPDATE search_index_failures SET attempts = 1 WHERE release_id = 702")
        .execute(pool)
        .await
        .unwrap();
    scope.finish().await;

    let page = live.client.search_releases(&all_releases()).await.unwrap();
    assert_eq!(page.ids.len(), 2);
    assert!(lease(pool, 701).await.is_none());
    assert!(lease(pool, 703).await.is_none());
    let kept = lease(pool, 702).await.unwrap();
    assert_eq!((kept.0.as_str(), kept.1), ("deferred", 1));
    let due: bool = sqlx::query_scalar(
        "SELECT next_attempt_at <= UTC_TIMESTAMP() + INTERVAL 1 SECOND FROM search_index_failures WHERE release_id = 702",
    )
    .fetch_one(pool)
    .await
    .unwrap();
    assert!(due, "handed to repair at once");

    live.client
        .sql(&format!("DROP TABLE {}", live.table))
        .await
        .unwrap();
    live.db.drop_database().await.unwrap();
}

#[tokio::test]
async fn a_live_lease_is_respected_and_a_dead_one_taken_over() {
    let Some(live) = live().await else {
        return;
    };
    let pool = &live.db.pool;
    seed_release(pool, 801).await;
    seed_release(pool, 802).await;
    let until = chrono::Utc::now().naive_utc() + chrono::Duration::seconds(600);
    let expired = chrono::Utc::now().naive_utc() - chrono::Duration::seconds(5);
    for (id, at) in [(801, until), (802, expired)] {
        sqlx::query("INSERT INTO search_index_failures (release_id, operation, attempts, last_error, next_attempt_at) VALUES (?, 'deferred', 0, 'deferred:other', ?)")
            .bind(id)
            .bind(at)
            .execute(pool)
            .await
            .unwrap();
    }
    let mut scope = live.client.defer_release_updates(pool);
    scope.update_release(801).await;
    scope.update_release(802).await;

    // No lease of its own on 801, so it's indexed now, and the other scope keeps its lease.
    let page = live.client.search_releases(&all_releases()).await.unwrap();
    assert_eq!(page.ids, [801]);
    assert_eq!(
        lease(pool, 801).await.unwrap().2.as_deref(),
        Some("deferred:other")
    );
    assert_ne!(
        lease(pool, 802).await.unwrap().2.as_deref(),
        Some("deferred:other")
    );

    scope.finish().await;
    assert!(lease(pool, 802).await.is_none());
    assert!(lease(pool, 801).await.is_some());

    live.client
        .sql(&format!("DROP TABLE {}", live.table))
        .await
        .unwrap();
    live.db.drop_database().await.unwrap();
}

#[tokio::test]
async fn update_releases_refreshes_removes_and_reports_failures() {
    let Some(live) = live().await else {
        return;
    };
    let pool = &live.db.pool;
    seed_release(pool, 901).await;
    seed_release(pool, 902).await;
    sqlx::query("INSERT INTO search_index_failures (release_id, operation, attempts, next_attempt_at) VALUES (901, 'upsert', 2, NOW())")
        .execute(pool)
        .await
        .unwrap();
    assert!(
        live.client
            .update_releases(pool, &[901, 902, 902, -1, 903])
            .await
            .is_empty()
    );
    let page = live.client.search_releases(&all_releases()).await.unwrap();
    assert_eq!(page.ids.len(), 2);
    let resolved: bool = sqlx::query_scalar(
        "SELECT resolved_at IS NOT NULL FROM search_index_failures WHERE release_id = 901",
    )
    .fetch_one(pool)
    .await
    .unwrap();
    assert!(resolved);

    assert!(live.client.delete_releases(pool, &[901, 902, 901]).await);
    let page = live.client.search_releases(&all_releases()).await.unwrap();
    assert!(page.ids.is_empty());
    assert!(!live.client.delete_releases(pool, &[0]).await);

    let mut config = SearchConfig::new("http://127.0.0.1:9");
    config.retry_delay_ms = 0;
    let unreachable = SearchClient::new(config, None).unwrap();
    assert_eq!(unreachable.update_releases(pool, &[901]).await, [901]);
    let operation: String =
        sqlx::query_scalar("SELECT operation FROM search_index_failures WHERE release_id = 901")
            .fetch_one(pool)
            .await
            .unwrap();
    assert_eq!(operation, "upsert");

    live.client
        .sql(&format!("DROP TABLE {}", live.table))
        .await
        .unwrap();
    live.db.drop_database().await.unwrap();
}
