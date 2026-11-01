use anyhow::Result;
use nntmux_testkit::TestDb;

#[tokio::test]
async fn a_fresh_database_gets_the_whole_schema_and_a_second_run_does_nothing() -> Result<()> {
    let Some(db) = TestDb::create_empty().await? else {
        return Ok(());
    };
    let ran = nntmux_db::migrate::run(&db.pool).await?;
    assert_eq!(ran, [1, 2]);

    let tables: i64 = sqlx::query_scalar(
        "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()",
    )
    .fetch_one(&db.pool)
    .await?;
    assert!(tables >= 149, "only {tables} tables");

    let categories: i64 = sqlx::query_scalar("SELECT COUNT(*) FROM categories")
        .fetch_one(&db.pool)
        .await?;
    assert_eq!(categories, 68);

    let triggers: i64 = sqlx::query_scalar(
        "SELECT COUNT(*) FROM information_schema.triggers WHERE trigger_schema = DATABASE()",
    )
    .fetch_one(&db.pool)
    .await?;
    assert_eq!(triggers, 3);

    assert!(nntmux_db::migrate::run(&db.pool).await?.is_empty());
    db.drop_database().await
}

const LARAVEL_BASELINE: &str = "2026_10_20_200000_create_database_fingerprint_storage";

async fn laravel_database(newest: Option<&str>) -> Result<Option<TestDb>> {
    let Some(db) = TestDb::create_empty().await? else {
        return Ok(None);
    };
    sqlx::query(
        "CREATE TABLE migrations (id INT AUTO_INCREMENT PRIMARY KEY, migration VARCHAR(191))",
    )
    .execute(&db.pool)
    .await?;
    if let Some(newest) = newest {
        sqlx::query("INSERT INTO migrations (migration) VALUES ('2014_01_16_000000_a'), (?)")
            .bind(newest)
            .execute(&db.pool)
            .await?;
    }
    Ok(Some(db))
}

#[tokio::test]
async fn a_database_laravel_built_only_records_the_baseline() -> Result<()> {
    let Some(db) = laravel_database(Some(LARAVEL_BASELINE)).await? else {
        return Ok(());
    };
    assert!(nntmux_db::migrate::run(&db.pool).await?.is_empty());

    let recorded: i64 = sqlx::query_scalar("SELECT COUNT(*) FROM rust_migrations")
        .fetch_one(&db.pool)
        .await?;
    assert_eq!(recorded, 2);
    let tables: i64 = sqlx::query_scalar(
        "SELECT COUNT(*) FROM information_schema.tables
         WHERE table_schema = DATABASE() AND table_name = 'releases'",
    )
    .fetch_one(&db.pool)
    .await?;
    assert_eq!(tables, 0);
    assert!(nntmux_db::migrate::run(&db.pool).await?.is_empty());
    db.drop_database().await
}

#[tokio::test]
async fn a_laravel_database_behind_the_baseline_is_refused() -> Result<()> {
    let Some(db) = laravel_database(Some("2026_01_01_000000_older")).await? else {
        return Ok(());
    };
    let refused = nntmux_db::migrate::run(&db.pool).await.unwrap_err();
    assert!(format!("{refused:#}").contains("refusing"), "{refused:#}");
    db.drop_database().await
}

#[tokio::test]
async fn an_interrupted_fresh_install_is_refused_rather_than_recorded() -> Result<()> {
    // 0001 creates an empty `migrations` table partway through.
    let Some(db) = laravel_database(None).await? else {
        return Ok(());
    };
    let refused = nntmux_db::migrate::run(&db.pool).await.unwrap_err();
    assert!(format!("{refused:#}").contains("refusing"), "{refused:#}");
    let recorded: i64 = sqlx::query_scalar("SELECT COUNT(*) FROM rust_migrations")
        .fetch_one(&db.pool)
        .await?;
    assert_eq!(recorded, 0);
    db.drop_database().await
}

#[tokio::test]
async fn tables_with_no_laravel_record_are_refused() -> Result<()> {
    let Some(db) = TestDb::create_empty().await? else {
        return Ok(());
    };
    sqlx::query("CREATE TABLE stray (id INT PRIMARY KEY)")
        .execute(&db.pool)
        .await?;
    assert!(nntmux_db::migrate::run(&db.pool).await.is_err());
    db.drop_database().await
}

#[tokio::test]
async fn connections_truncate_overlong_values_like_laravel_did() -> Result<()> {
    let Some(db) = TestDb::create_empty().await? else {
        return Ok(());
    };
    sqlx::query("CREATE TABLE names (name VARCHAR(4))")
        .execute(&db.pool)
        .await?;
    sqlx::query("INSERT INTO names VALUES ('too long')")
        .execute(&db.pool)
        .await?;
    let stored: String = sqlx::query_scalar("SELECT name FROM names")
        .fetch_one(&db.pool)
        .await?;
    assert_eq!(stored, "too ");
    db.drop_database().await
}
