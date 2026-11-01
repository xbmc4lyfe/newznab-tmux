//! The role against the real schema: leadership, outage compensation and the activity marker.

use std::time::Duration;

use anyhow::Result;
use chrono::Utc;
use nntmux_core::Config;
use nntmux_runtime::{Ctx, RoleArgs, Shutdown};
use nntmux_supervisor::leader::Leader;
use nntmux_supervisor::monitor;
use nntmux_testkit::TestDb;
use sqlx::MySqlPool;

async fn context(db: &MySqlPool) -> Result<Option<Ctx>> {
    let Ok(url) = std::env::var("TEST_REDIS_URL") else {
        eprintln!("TEST_REDIS_URL is unset, skipping");
        return Ok(None);
    };
    let redis = redis::aio::ConnectionManager::new(redis::Client::open(url)?).await?;
    Ok(Some(Ctx {
        config: std::sync::Arc::new(Config::from_env()?),
        db: db.clone(),
        redis,
    }))
}

fn once() -> RoleArgs {
    RoleArgs {
        concurrency: 1,
        idle_seconds: 1,
        once: true,
    }
}

async fn waiting_collection(db: &MySqlPool, age_seconds: i64) -> Result<u64> {
    Ok(sqlx::query(
        "INSERT INTO collections (subject, fromname, date, xref, totalfiles, groups_id, collectionhash, \
         dateadded, filecheck) VALUES ('s', 'f', NOW(), '', 1, 1, MD5(RAND()), NOW() - INTERVAL ? SECOND, 0)",
    )
    .bind(age_seconds)
    .execute(db)
    .await?
    .last_insert_id())
}

async fn age_of(db: &MySqlPool, id: u64) -> Result<i64> {
    Ok(sqlx::query_scalar(
        "SELECT TIMESTAMPDIFF(SECOND, dateadded, NOW()) FROM collections WHERE id = ?",
    )
    .bind(id)
    .fetch_one(db)
    .await?)
}

async fn set_last_active(db: &MySqlPool, ago_seconds: i64) -> Result<()> {
    sqlx::query(
        "INSERT INTO ingestion_activity (id, last_active_at) VALUES (1, ?) \
         ON DUPLICATE KEY UPDATE last_active_at = VALUES(last_active_at)",
    )
    .bind(Utc::now().timestamp() - ago_seconds)
    .execute(db)
    .await?;
    Ok(())
}

#[tokio::test]
async fn a_start_gives_collections_back_the_outage() -> Result<()> {
    let Some(test) = TestDb::create().await? else {
        return Ok(());
    };
    let Some(ctx) = context(&test.pool).await? else {
        return Ok(());
    };
    let waiting = waiting_collection(&test.pool, 3600 + 600).await?;
    set_last_active(&test.pool, 3600).await?;

    nntmux_supervisor::run(ctx, once(), Shutdown::default()).await?;

    let age = age_of(&test.pool, waiting).await?;
    assert!((550..=700).contains(&age), "the age was {age}");
    test.drop_database().await
}

#[tokio::test]
async fn a_paused_start_leaves_the_outage_for_the_resume() -> Result<()> {
    let Some(test) = TestDb::create().await? else {
        return Ok(());
    };
    let Some(ctx) = context(&test.pool).await? else {
        return Ok(());
    };
    sqlx::query(
        "INSERT INTO processing_runtime_states (id, stop_requested) VALUES (1, 1) \
         ON DUPLICATE KEY UPDATE stop_requested = 1",
    )
    .execute(&test.pool)
    .await?;
    let waiting = waiting_collection(&test.pool, 3600 + 600).await?;
    set_last_active(&test.pool, 3600).await?;

    nntmux_supervisor::run(ctx.clone(), once(), Shutdown::default()).await?;
    assert!(age_of(&test.pool, waiting).await? >= 4200);

    // The monitor records nothing while paused, then compensates on the first beat after the resume.
    let stop = Shutdown::default();
    let beating = tokio::spawn(monitor::run(ctx.clone(), stop.clone()));
    tokio::time::sleep(Duration::from_millis(300)).await;
    assert!(age_of(&test.pool, waiting).await? >= 4200);
    stop.request();
    beating.await?;

    sqlx::query("UPDATE processing_runtime_states SET stop_requested = 0 WHERE id = 1")
        .execute(&test.pool)
        .await?;
    let stop = Shutdown::default();
    let beating = tokio::spawn(monitor::run(ctx, stop.clone()));
    let mut age = i64::MAX;
    for _ in 0..50 {
        age = age_of(&test.pool, waiting).await?;
        if age < 1000 {
            break;
        }
        tokio::time::sleep(Duration::from_millis(100)).await;
    }
    stop.request();
    beating.await?;
    assert!(age < 1000, "the age was {age}");
    test.drop_database().await
}

#[tokio::test]
async fn only_one_supervisor_leads_and_a_dead_one_frees_the_lock() -> Result<()> {
    let Some(test) = TestDb::create().await? else {
        return Ok(());
    };
    let stop = Shutdown::default();
    let mut first = Leader::acquire(&test.pool, &stop)
        .await?
        .expect("nobody else holds the lock");
    assert!(first.still_held().await);

    let waiting_stop = Shutdown::default();
    let second = tokio::spawn({
        let (pool, stop) = (test.pool.clone(), waiting_stop.clone());
        async move { Leader::acquire(&pool, &stop).await }
    });
    tokio::time::sleep(Duration::from_millis(300)).await;
    assert!(!second.is_finished());
    drop(first);
    let mut second = second.await??.expect("the lock was released");
    assert!(second.still_held().await);

    let stopped = Shutdown::default();
    stopped.request();
    assert!(Leader::acquire(&test.pool, &stopped).await?.is_none());
    drop(second);
    test.drop_database().await
}
