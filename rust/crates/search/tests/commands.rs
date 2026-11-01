//! Repair, reconcile and table commands against real services. Skips unless
//! `TEST_DATABASE_URL` and `TEST_MANTICORE_HTTP` are set.

use nntmux_runtime::{RoleArgs, Shutdown};
use nntmux_search::config::{IndexNames, LOGICAL_INDEXES};
use nntmux_search::{
    Command, PredbHit, ReconcileOptions, ReleaseDocument, ReleaseSearchQuery, SearchClient,
    SearchConfig, run_command, sync_loop,
};
use nntmux_testkit::TestDb;
use sqlx::MySqlPool;

fn client_for(address: &str) -> SearchClient {
    let prefix = format!("t{:016x}_", rand::random::<u64>());
    let mut config = SearchConfig::new(format!("http://{address}"));
    config.indexes = IndexNames::prefixed(&prefix);
    SearchClient::new(config, None).unwrap()
}

async fn live() -> Option<(TestDb, SearchClient)> {
    let Ok(address) = std::env::var("TEST_MANTICORE_HTTP") else {
        eprintln!("TEST_MANTICORE_HTTP is unset, skipping");
        return None;
    };
    let db = TestDb::create().await.unwrap()?;
    let client = client_for(&address);
    client.create_indexes(&[], false).await.unwrap();
    Some((db, client))
}

async fn finish(db: TestDb, client: SearchClient) {
    for logical in LOGICAL_INDEXES {
        let table = client.config().indexes.table(logical).to_owned();
        client
            .sql(&format!("DROP TABLE IF EXISTS {table}"))
            .await
            .unwrap();
    }
    db.drop_database().await.unwrap();
}

async fn seed_release(pool: &MySqlPool, id: i64) {
    sqlx::query(
        "INSERT INTO releases (id, name, searchname, groups_id, guid, leftguid, categories_id, passwordstatus, postdate, adddate) \
         VALUES (?, 'n', ?, 0, ?, 'a', 2040, 0, '2024-01-01 00:00:00', UTC_TIMESTAMP())",
    )
    .bind(id)
    .bind(format!("Release.{id}"))
    .bind(format!("{id:0>40}"))
    .execute(pool)
    .await
    .unwrap();
}

async fn failure(
    pool: &MySqlPool,
    id: i64,
    operation: &str,
    attempts: u32,
    token: &str,
    due: bool,
) {
    let offset = if due { -5 } else { 600 };
    sqlx::query(
        "INSERT INTO search_index_failures (release_id, operation, attempts, last_error, next_attempt_at) \
         VALUES (?, ?, ?, ?, ?)",
    )
    .bind(id)
    .bind(operation)
    .bind(attempts)
    .bind(token)
    .bind(chrono::Utc::now().naive_utc() + chrono::Duration::seconds(offset))
    .execute(pool)
    .await
    .unwrap();
}

async fn indexed_ids(client: &SearchClient) -> Vec<i64> {
    let mut ids = client.indexed_release_ids(100, 0).await.unwrap();
    ids.sort_unstable();
    ids
}

async fn row_exists(pool: &MySqlPool, id: i64) -> bool {
    sqlx::query_scalar::<_, i64>("SELECT COUNT(*) FROM search_index_failures WHERE release_id = ?")
        .bind(id)
        .fetch_one(pool)
        .await
        .unwrap()
        > 0
}

#[tokio::test]
async fn repair_retries_each_kind_of_row_when_due() {
    let Some((db, client)) = live().await else {
        return;
    };
    let pool = &db.pool;
    for id in [1, 2, 3, 4, 5] {
        seed_release(pool, id).await;
    }
    client
        .bulk_insert_releases(&[ReleaseDocument {
            id: 50,
            searchname: "gone".to_owned(),
            ..ReleaseDocument::default()
        }])
        .await;
    failure(pool, 1, "upsert", 1, "insertRelease", true).await;
    failure(pool, 2, "upsert", 1, "insertRelease", false).await;
    failure(pool, 50, "delete", 1, "deleteReleases: x", true).await;
    failure(pool, 3, "deferred", 0, "deferred:dead", true).await;
    failure(pool, 4, "deferred", 0, "deferred:live", false).await;
    // A resolved row isn't retried.
    failure(pool, 5, "upsert", 1, "x", true).await;
    sqlx::query("UPDATE search_index_failures SET resolved_at = NOW() WHERE release_id = 5")
        .execute(pool)
        .await
        .unwrap();

    assert_eq!(client.due_failures(pool, 100).await.unwrap(), [1, 50, 3]);
    let report = client.repair(pool, 100).await.unwrap();
    assert_eq!((report.due, report.repaired), (3, 3));
    assert_eq!(indexed_ids(&client).await, [1, 3]);
    assert!(!row_exists(pool, 3).await, "the expired lease is cleared");
    assert!(row_exists(pool, 4).await, "the live lease stays");
    assert_eq!(client.repair(pool, 100).await.unwrap().due, 0);

    finish(db, client).await;
}

#[tokio::test]
async fn repair_backs_off_a_lease_it_cannot_refresh() {
    let Some(db) = TestDb::create().await.unwrap() else {
        return;
    };
    let pool = &db.pool;
    seed_release(pool, 7).await;
    failure(pool, 7, "deferred", 0, "deferred:dead", true).await;
    let mut config = SearchConfig::new("http://127.0.0.1:9");
    config.retry_delay_ms = 0;
    let unreachable = SearchClient::new(config, None).unwrap();

    let report = unreachable.repair(pool, 100).await.unwrap();
    assert_eq!((report.due, report.repaired), (1, 0));
    let (operation, token, later): (String, String, bool) = sqlx::query_as(
        "SELECT operation, last_error, next_attempt_at > ? FROM search_index_failures WHERE release_id = 7",
    )
    .bind(chrono::Utc::now().naive_utc())
    .fetch_one(pool)
    .await
    .unwrap();
    assert_eq!(operation, "deferred");
    assert_eq!(token, "deferred:dead");
    assert!(later);
    db.drop_database().await.unwrap();
}

fn options() -> ReconcileOptions {
    ReconcileOptions {
        since: None,
        chunk: 50,
        in_batch: 50,
        orphans: true,
        reindex: false,
    }
}

#[tokio::test]
async fn reconcile_finds_and_repairs_missing_and_orphaned_releases() {
    let Some((db, client)) = live().await else {
        return;
    };
    let pool = &db.pool;
    for id in 1..=120 {
        seed_release(pool, id).await;
    }
    // Index all but three, and one document with no row. More than the implicit LIMIT 20.
    let documents: Vec<ReleaseDocument> = (1..=117)
        .chain([900])
        .map(|id| ReleaseDocument {
            id,
            searchname: format!("Release {id}"),
            ..ReleaseDocument::default()
        })
        .collect();
    client.bulk_insert_releases(&documents).await;

    let report = client.reconcile_releases(pool, &options()).await.unwrap();
    assert_eq!(report.scanned, 120);
    assert_eq!(report.missing, [118, 119, 120]);
    assert_eq!(report.orphans, [900]);
    assert_eq!(report.reindexed, 0);
    let table = client.config().indexes.table("releases").to_owned();
    assert_eq!(client.count_index_documents(&table).await.unwrap(), 118);

    // The window excludes everything added before it.
    let window = ReconcileOptions {
        since: Some(chrono::Utc::now().naive_utc() + chrono::Duration::hours(1)),
        orphans: false,
        ..options()
    };
    assert_eq!(
        client
            .reconcile_releases(pool, &window)
            .await
            .unwrap()
            .scanned,
        0
    );

    let repair = ReconcileOptions {
        reindex: true,
        ..options()
    };
    let report = client.reconcile_releases(pool, &repair).await.unwrap();
    assert_eq!(report.reindexed, 3);
    assert!(report.orphans_removed);
    assert_eq!(client.count_index_documents(&table).await.unwrap(), 120);
    let clean = client.reconcile_releases(pool, &options()).await.unwrap();
    assert!(clean.missing.is_empty() && clean.orphans.is_empty());

    finish(db, client).await;
}

#[tokio::test]
async fn reconcile_stops_on_a_failed_probe_instead_of_reporting_everything_missing() {
    let Some(db) = TestDb::create().await.unwrap() else {
        return;
    };
    seed_release(&db.pool, 1).await;
    let unreachable = SearchClient::new(SearchConfig::new("http://127.0.0.1:9"), None).unwrap();
    assert!(
        unreachable
            .reconcile_releases(&db.pool, &options())
            .await
            .is_err()
    );
    db.drop_database().await.unwrap();
}

async fn seed_predb(pool: &MySqlPool, id: i64) {
    sqlx::query("INSERT INTO predb (id, title, filename, source) VALUES (?, ?, ?, 's')")
        .bind(id)
        .bind(format!("Title.{id}"))
        .bind(format!("file.{id}"))
        .execute(pool)
        .await
        .unwrap();
}

#[tokio::test]
async fn reconcile_predb_repairs_with_inserts_and_keeps_fresher_documents() {
    let Some((db, client)) = live().await else {
        return;
    };
    let pool = &db.pool;
    for id in 1..=60 {
        seed_predb(pool, id).await;
    }
    let indexed: Vec<PredbHit> = (1..=58)
        .map(|id| PredbHit {
            id,
            title: format!("Fresher.{id}"),
            filename: String::new(),
            source: "s".to_owned(),
        })
        .collect();
    client.bulk_insert_predb(&indexed, true).await;

    let report = client.reconcile_predb(pool, 50, 50, false).await;
    assert_eq!(report.failure, None);
    assert_eq!(
        (report.scanned, report.missing, report.repaired),
        (60, 2, 0)
    );
    assert_eq!(report.sample, [59, 60]);
    assert_eq!((report.last_completed_id, report.max_id), (60, 60));

    let report = client.reconcile_predb(pool, 50, 50, true).await;
    assert_eq!(report.failure, None);
    assert_eq!(report.repaired, 2);
    let hit = client.match_predb_exact("Title.59").await.unwrap().unwrap();
    assert_eq!(hit.filename, "file.59");
    assert!(
        client
            .match_predb_exact("Fresher.1")
            .await
            .unwrap()
            .is_some()
    );
    assert_eq!(client.reconcile_predb(pool, 50, 50, false).await.missing, 0);

    let unreachable = SearchClient::new(SearchConfig::new("http://127.0.0.1:9"), None).unwrap();
    let report = unreachable.reconcile_predb(pool, 50, 50, false).await;
    assert!(report.failure.is_some());
    assert_eq!(report.last_completed_id, 0);

    finish(db, client).await;
}

#[tokio::test]
async fn inspect_reports_compatible_tables_and_what_needs_a_rebuild() {
    let Some((db, client)) = live().await else {
        return;
    };
    let report = client.inspect().await.unwrap();
    assert!(report.compatible, "{report:?}");
    assert!(report.version.starts_with(|c: char| c.is_ascii_digit()));

    // Creating again leaves existing tables, and --drop rebuilds them.
    let lines = client
        .create_indexes(&["predb".to_owned()], false)
        .await
        .unwrap();
    assert!(lines[0].contains("already exists"), "{lines:?}");
    let table = client.config().indexes.table("predb").to_owned();
    client
        .replace_documents(&table, &[(1, serde_json::json!({"title": "x"}))])
        .await
        .unwrap();
    client
        .create_indexes(&["predb".to_owned()], true)
        .await
        .unwrap();
    assert_eq!(client.count_index_documents(&table).await.unwrap(), 0);
    assert!(
        client
            .create_indexes(&["nope".to_owned()], false)
            .await
            .is_err()
    );

    let movies = client.config().indexes.table("movies").to_owned();
    client.sql(&format!("DROP TABLE {movies}")).await.unwrap();
    client
        .sql(&format!(
            "CREATE TABLE {movies}(title text, imdbid integer)"
        ))
        .await
        .unwrap();
    let tv = client.config().indexes.table("tvshows").to_owned();
    client.sql(&format!("DROP TABLE {tv}")).await.unwrap();

    let report = client.inspect().await.unwrap();
    assert!(!report.compatible);
    let status = &report.tables[&movies];
    assert!(status.needs_rebuild);
    assert!(status.missing.contains(&"plot".to_owned()));
    assert!(status.incompatible.contains_key("imdbid"));
    assert_eq!(
        status.missing_settings,
        ["min_infix_len", "index_field_lengths"]
    );
    assert!(report.tables[&tv].error.is_some());
    assert!(!report.tables[client.config().indexes.table("music")].needs_rebuild);

    client
        .create_indexes(&["tvshows".to_owned()], false)
        .await
        .unwrap();
    finish(db, client).await;
}

#[tokio::test]
async fn the_sync_role_query_reads_committed_search_documents() {
    let Some((db, client)) = live().await else {
        return;
    };
    seed_release(&db.pool, 11).await;
    failure(&db.pool, 11, "upsert", 1, "insertRelease", true).await;
    client.repair(&db.pool, 100).await.unwrap();
    let page = client
        .search_releases(&ReleaseSearchQuery::default())
        .await
        .unwrap();
    assert_eq!(page.ids, [11]);
    finish(db, client).await;
}

#[tokio::test]
async fn every_command_runs_against_real_services() {
    let Some((db, client)) = live().await else {
        return;
    };
    let pool = &db.pool;
    seed_release(pool, 21).await;
    assert!(client.update_release(pool, 21).await);
    let guid = format!("{:0>40}", 21);
    let commands = [
        Command::CreateIndexes {
            drop: false,
            indexes: vec!["predb".to_owned()],
        },
        Command::CreateMediaIndexes { drop: false },
        Command::Inspect { json: true },
        Command::Inspect { json: false },
        Command::CheckIndex {
            index: "releases".to_owned(),
        },
        Command::Diag {
            ids: vec!["21".to_owned(), guid, "999".to_owned()],
            name: None,
            limit: 20,
            show_fields: true,
        },
        Command::Diag {
            ids: Vec::new(),
            name: Some("Release.2".to_owned()),
            limit: 20,
            show_fields: false,
        },
        Command::Reconcile {
            predb: false,
            since: "24h".to_owned(),
            all: false,
            chunk: 1000,
            in_batch: 500,
            orphans: true,
            reindex: true,
            dry_run: true,
        },
        Command::Reconcile {
            predb: true,
            since: "24h".to_owned(),
            all: true,
            chunk: 1000,
            in_batch: 500,
            orphans: false,
            reindex: false,
            dry_run: false,
        },
        Command::Repair {
            limit: 100,
            dry_run: true,
        },
        Command::Repair {
            limit: 100,
            dry_run: false,
        },
    ];
    for command in commands {
        let name = format!("{command:?}");
        run_command(&client, pool, command)
            .await
            .unwrap_or_else(|error| panic!("{name}: {error:#}"));
    }

    let refused = Command::Reconcile {
        predb: true,
        since: "24h".to_owned(),
        all: false,
        chunk: 1000,
        in_batch: 500,
        orphans: false,
        reindex: false,
        dry_run: false,
    };
    assert!(run_command(&client, pool, refused).await.is_err());
    let unknown = Command::CheckIndex {
        index: "nope".to_owned(),
    };
    assert!(run_command(&client, pool, unknown).await.is_err());

    finish(db, client).await;
}

fn role_args(once: bool) -> RoleArgs {
    RoleArgs {
        concurrency: 1,
        idle_seconds: 0,
        once,
    }
}

#[tokio::test]
async fn the_sync_role_repairs_and_holds_off_while_paused() {
    let Some((db, client)) = live().await else {
        return;
    };
    let pool = &db.pool;
    seed_release(pool, 31).await;
    failure(pool, 31, "upsert", 1, "insertRelease", true).await;
    let stop = Shutdown::default();

    sync_loop(
        &client,
        pool,
        || async { Ok(None::<()>) },
        &role_args(true),
        &stop,
    )
    .await
    .unwrap();
    assert!(
        indexed_ids(&client).await.is_empty(),
        "paused means no repair"
    );

    sync_loop(
        &client,
        pool,
        || async { Ok(Some(())) },
        &role_args(true),
        &stop,
    )
    .await
    .unwrap();
    assert_eq!(indexed_ids(&client).await, [31]);

    finish(db, client).await;
}

#[tokio::test]
async fn the_sync_role_reports_failures_once_and_survives_them_otherwise() {
    let Some(db) = TestDb::create().await.unwrap() else {
        return;
    };
    let pool = &db.pool;
    let client = SearchClient::new(SearchConfig::new("http://127.0.0.1:9"), None).unwrap();
    let stop = Shutdown::default();

    let paused_failed = sync_loop(
        &client,
        pool,
        || async { Err::<Option<()>, _>(anyhow::anyhow!("the database went away")) },
        &role_args(true),
        &stop,
    )
    .await;
    assert!(paused_failed.is_err());

    sqlx::query("DROP TABLE search_index_failures")
        .execute(pool)
        .await
        .unwrap();
    let repair_failed = sync_loop(
        &client,
        pool,
        || async { Ok(Some(())) },
        &role_args(true),
        &stop,
    )
    .await;
    assert!(repair_failed.is_err());

    // A long-running role keeps going through both kinds of failure until told to stop.
    let calls = std::sync::atomic::AtomicU32::new(0);
    let looping = sync_loop(
        &client,
        pool,
        || {
            let call = calls.fetch_add(1, std::sync::atomic::Ordering::SeqCst);
            let stop = stop.clone();
            async move {
                match call {
                    0 => Err(anyhow::anyhow!("the database went away")),
                    1 => Ok(Some(())),
                    _ => {
                        stop.request();
                        Ok(None)
                    }
                }
            }
        },
        &role_args(false),
        &stop,
    )
    .await;
    assert!(looping.is_ok());
    assert_eq!(calls.load(std::sync::atomic::Ordering::SeqCst), 3);

    db.drop_database().await.unwrap();
}
