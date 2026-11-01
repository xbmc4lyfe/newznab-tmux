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

#[tokio::test]
async fn a_database_laravel_built_only_records_the_baseline() -> Result<()> {
    let Some(db) = TestDb::create_empty().await? else {
        return Ok(());
    };
    sqlx::query("CREATE TABLE migrations (id INT PRIMARY KEY)")
        .execute(&db.pool)
        .await?;
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
    db.drop_database().await
}
