//! Health checks open one incident per outage, resolve it on recovery, and keep the status current.

use std::sync::Arc;

use anyhow::Result;
use nntmux_core::Config;
use nntmux_ops::site_status;
use nntmux_runtime::Ctx;
use nntmux_testkit::TestDb;

async fn context(test: &TestDb) -> Result<Option<Ctx>> {
    let Ok(url) = std::env::var("TEST_REDIS_URL") else {
        return Ok(None);
    };
    let mut config = Config::from_env()?;
    config.database.database.clone_from(&test.name);
    Ok(Some(Ctx {
        config: Arc::new(config),
        db: test.pool.clone(),
        redis: redis::aio::ConnectionManager::new(redis::Client::open(url)?).await?,
    }))
}

async fn status(ctx: &Ctx, slug: &str) -> Result<(String, Option<u32>)> {
    Ok(
        sqlx::query_as("SELECT status, response_time_ms FROM service_statuses WHERE slug = ?")
            .bind(slug)
            .fetch_one(&ctx.db)
            .await?,
    )
}

async fn incidents(ctx: &Ctx) -> Result<Vec<(String, String)>> {
    Ok(
        sqlx::query_as("SELECT status, impact FROM service_incidents ORDER BY id")
            .fetch_all(&ctx.db)
            .await?,
    )
}

#[tokio::test]
async fn an_outage_opens_one_incident_and_recovery_resolves_it() -> Result<()> {
    let Some(test) = TestDb::create().await? else {
        return Ok(());
    };
    let Some(ctx) = context(&test).await? else {
        return Ok(());
    };
    sqlx::query("DELETE FROM service_statuses")
        .execute(&ctx.db)
        .await?;
    sqlx::query(
        "INSERT INTO service_statuses (name, slug, endpoint_url, check_type, probe_identifier, status, is_enabled) VALUES \
         ('Web', 'web', '/up', 'http', NULL, 'operational', 1), \
         ('Db', 'db', NULL, 'probe', 'database', 'operational', 1), \
         ('Bad probe', 'bad', NULL, 'probe', 'nope', 'operational', 1), \
         ('Idle', 'idle', NULL, 'http', NULL, 'operational', 1), \
         ('Off', 'off', '/up', 'http', NULL, 'operational', 0)",
    )
    .execute(&ctx.db)
    .await?;
    let report = site_status::check_all(&ctx, 30).await?;
    assert_eq!(report.len(), 4, "{report:?}");
    assert!(
        report.contains(&("Db".to_owned(), "Healthy".to_owned())),
        "{report:?}"
    );
    assert!(report.contains(&("Idle".to_owned(), "Skipped (no endpoint)".to_owned())));
    let open = incidents(&ctx).await?;
    assert_eq!(open, [("investigating".to_owned(), "major".to_owned())]);
    assert_eq!(status(&ctx, "bad").await?.0, "partial_outage");
    assert_eq!(status(&ctx, "db").await?.0, "operational");

    // Still failing: no second incident.
    site_status::check_all(&ctx, 30).await?;
    assert_eq!(incidents(&ctx).await?.len(), 1);
    // Give the outage a measurable length, so it counts against uptime below.
    sqlx::query("UPDATE service_incidents SET started_at = NOW() - INTERVAL 1 HOUR")
        .execute(&ctx.db)
        .await?;

    // Recovery: the probe now works, so the incident resolves and the status clears.
    sqlx::query("UPDATE service_statuses SET probe_identifier = 'database' WHERE slug = 'bad'")
        .execute(&ctx.db)
        .await?;
    site_status::check_all(&ctx, 30).await?;
    assert_eq!(
        incidents(&ctx).await?,
        [("resolved".to_owned(), "major".to_owned())]
    );
    assert_eq!(status(&ctx, "bad").await?.0, "operational");

    // The outage counts against uptime, and the disabled service is left alone.
    let (uptime, checked): (f64, Option<String>) = sqlx::query_as(
        "SELECT CAST(uptime_percentage AS DOUBLE), CAST(last_checked_at AS CHAR) FROM service_statuses WHERE slug = 'bad'",
    )
    .fetch_one(&ctx.db)
    .await?;
    assert!(uptime <= 99.99, "{uptime}");
    assert!(checked.is_some());
    let off: Option<String> = sqlx::query_scalar(
        "SELECT CAST(last_checked_at AS CHAR) FROM service_statuses WHERE slug = 'off'",
    )
    .fetch_one(&ctx.db)
    .await?;
    assert_eq!(off, None);
    test.drop_database().await
}
