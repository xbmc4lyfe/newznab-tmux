//! `nntmux-ctl init` against real MariaDB and Manticore. Skips unless `TEST_DATABASE_URL` and
//! `TEST_MANTICORE_HTTP` are set.

use std::path::Path;

use nntmux_ctl::init::{Admin, Settings, initialize};
use nntmux_search::config::{IndexNames, LOGICAL_INDEXES};
use nntmux_search::{SearchClient, SearchConfig};
use nntmux_testkit::TestDb;

fn settings(storage: &Path) -> Settings {
    Settings {
        admin: Admin::new(
            Some("admin".to_owned()),
            Some("admin@example.com".to_owned()),
            Some("correct horse battery".to_owned()),
        )
        .unwrap(),
        storage: storage.to_path_buf(),
        directories: vec![
            storage.to_path_buf(),
            storage.join("nzb"),
            storage.join("covers"),
            storage.join("tmp/unrar"),
        ],
    }
}

fn search_client(address: &str) -> SearchClient {
    let prefix = format!("t{:016x}_", rand::random::<u64>());
    let mut config = SearchConfig::new(format!("http://{address}"));
    config.indexes = IndexNames::prefixed(&prefix);
    SearchClient::new(config, None).unwrap()
}

async fn drop_indexes(client: &SearchClient) {
    for logical in LOGICAL_INDEXES {
        let table = client.config().indexes.table(logical).to_owned();
        client
            .sql(&format!("DROP TABLE IF EXISTS {table}"))
            .await
            .unwrap();
    }
}

#[tokio::test]
async fn initializes_an_empty_database_once() {
    let Ok(address) = std::env::var("TEST_MANTICORE_HTTP") else {
        eprintln!("TEST_MANTICORE_HTTP is unset, skipping");
        return;
    };
    let Some(db) = TestDb::create_empty().await.unwrap() else {
        return;
    };
    let search = search_client(&address);
    let storage = tempfile::tempdir().unwrap();
    let settings = settings(storage.path());

    initialize(&db.pool, &search, &settings).await.unwrap();

    let (roles_id, verified, hash): (i32, bool, String) = sqlx::query_as(
        "SELECT roles_id, email_verified_at IS NOT NULL, password FROM users WHERE username = 'admin'",
    )
    .fetch_one(&db.pool)
    .await
    .unwrap();
    assert_eq!((roles_id, verified), (2, true));
    assert!(bcrypt::verify("correct horse battery", &hash).unwrap());
    let role_rows: i64 = sqlx::query_scalar(
        "SELECT COUNT(*) FROM model_has_roles mr JOIN users u ON u.id = mr.model_id WHERE u.username = 'admin' AND mr.role_id = 2",
    )
    .fetch_one(&db.pool)
    .await
    .unwrap();
    assert_eq!(role_rows, 1);
    for directory in &settings.directories {
        assert!(directory.is_dir(), "{}", directory.display());
    }
    assert!(storage.path().join("install/install.lock").is_file());
    let report = search.inspect().await.unwrap();
    assert_eq!(report.tables.len(), LOGICAL_INDEXES.len());
    assert!(
        report.tables.values().all(|table| !table.needs_rebuild),
        "{:?}",
        report.tables
    );

    // A second run stops at the marker and leaves the account alone.
    let again = initialize(&db.pool, &search, &settings).await.unwrap_err();
    assert!(
        format!("{again:#}").contains("already initialized"),
        "{again:#}"
    );

    // Without the marker it still refuses, because the database has tables.
    std::fs::remove_file(storage.path().join("install/install.lock")).unwrap();
    let again = initialize(&db.pool, &search, &settings).await.unwrap_err();
    assert!(format!("{again:#}").contains("empty database"), "{again:#}");
    let users: i64 = sqlx::query_scalar("SELECT COUNT(*) FROM users")
        .fetch_one(&db.pool)
        .await
        .unwrap();
    assert_eq!(users, 1);

    drop_indexes(&search).await;
    db.drop_database().await.unwrap();
}

#[tokio::test]
async fn refuses_a_database_with_tables_before_writing() {
    let Some(db) = TestDb::create_empty().await.unwrap() else {
        return;
    };
    sqlx::query("CREATE TABLE releases (id INT PRIMARY KEY)")
        .execute(&db.pool)
        .await
        .unwrap();
    // Nothing should reach Manticore, so an unreachable address is fine.
    let search = search_client("127.0.0.1:9");
    let storage = tempfile::tempdir().unwrap();
    let settings = settings(storage.path());

    let error = initialize(&db.pool, &search, &settings).await.unwrap_err();
    assert!(format!("{error:#}").contains("empty database"), "{error:#}");
    let tables: Vec<String> = sqlx::query_scalar(
        "SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE()",
    )
    .fetch_all(&db.pool)
    .await
    .unwrap();
    assert_eq!(tables, ["releases"]);
    assert!(!storage.path().join("nzb").exists());
    assert!(!storage.path().join("install/install.lock").exists());

    db.drop_database().await.unwrap();
}
