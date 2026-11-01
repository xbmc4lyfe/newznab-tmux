//! The drain contract against real MariaDB and Redis.

use std::sync::Arc;
use std::time::Duration;

use anyhow::Result;
use chrono::Utc;
use nntmux_core::Config;
use nntmux_ops::runtime_state::{self, heartbeat_key};
use nntmux_runtime::{Ctx, Shutdown, busy};
use nntmux_testkit::TestDb;
use redis::AsyncCommands;

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

async fn flags(ctx: &Ctx) -> Result<(bool, bool)> {
    Ok(sqlx::query_as(
        "SELECT tmux_running, stop_requested FROM processing_runtime_states WHERE id = 1",
    )
    .fetch_one(&ctx.db)
    .await?)
}

async fn idle(ctx: &Ctx) -> Result<()> {
    for _ in 0..100 {
        if busy::active_roles(ctx).await?.is_empty() {
            return Ok(());
        }
        tokio::time::sleep(Duration::from_millis(20)).await;
    }
    anyhow::bail!("work stayed marked busy")
}

#[tokio::test]
async fn a_drain_pauses_then_resume_restores_only_for_its_owner() -> Result<()> {
    let Some(test) = TestDb::create().await? else {
        return Ok(());
    };
    let Some(ctx) = context(&test).await? else {
        return Ok(());
    };
    let signals = Shutdown::default();
    runtime_state::start(&ctx).await?;

    runtime_state::drain(&ctx, 5, Some("deploy-1"), &signals).await?;
    assert_eq!(flags(&ctx).await?, (false, true));
    assert!(ctx.paused().await?);

    let other = runtime_state::resume(&ctx, Some("deploy-2")).await?;
    assert!(other.contains("owns no active drain"));
    assert_eq!(flags(&ctx).await?, (false, true));

    runtime_state::resume(&ctx, Some("deploy-1")).await?;
    assert_eq!(flags(&ctx).await?, (true, false));

    let gone = runtime_state::resume(&ctx, Some("deploy-1")).await?;
    assert!(gone.contains("owns no active drain"));
    assert!(runtime_state::resume(&ctx, None).await.is_err());
    test.drop_database().await
}

#[tokio::test]
async fn an_operator_drain_takes_over_from_an_automated_one() -> Result<()> {
    let Some(test) = TestDb::create().await? else {
        return Ok(());
    };
    let Some(ctx) = context(&test).await? else {
        return Ok(());
    };
    let signals = Shutdown::default();
    runtime_state::start(&ctx).await?;
    runtime_state::drain(&ctx, 5, Some("deploy-1"), &signals).await?;
    runtime_state::drain(&ctx, 5, None, &signals).await?;

    let automated = runtime_state::resume(&ctx, Some("deploy-1")).await?;
    assert!(automated.contains("owns no active drain"));
    assert_eq!(flags(&ctx).await?, (false, true));

    runtime_state::resume(&ctx, None).await?;
    assert_eq!(flags(&ctx).await?, (true, false));
    test.drop_database().await
}

#[tokio::test]
async fn a_timed_out_drain_names_the_roles_and_restores() -> Result<()> {
    let Some(test) = TestDb::create().await? else {
        return Ok(());
    };
    let Some(ctx) = context(&test).await? else {
        return Ok(());
    };
    runtime_state::start(&ctx).await?;
    let work = busy::mark(&ctx, "releases").await;

    let failure = runtime_state::drain(&ctx, 0, Some("deploy-1"), &Shutdown::default())
        .await
        .expect_err("work is still running");
    assert!(failure.to_string().contains("Blocking roles: releases."));
    assert_eq!(flags(&ctx).await?, (true, false));
    assert!(runtime_state::resume(&ctx, None).await.is_err());

    let interrupted = Shutdown::default();
    interrupted.request();
    assert!(
        runtime_state::drain(&ctx, 60, None, &interrupted)
            .await
            .is_err()
    );
    assert_eq!(flags(&ctx).await?, (true, false));

    drop(work);
    idle(&ctx).await?;
    runtime_state::drain(&ctx, 5, None, &Shutdown::default()).await?;
    test.drop_database().await
}

#[tokio::test]
async fn a_drain_waits_for_work_to_finish() -> Result<()> {
    let Some(test) = TestDb::create().await? else {
        return Ok(());
    };
    let Some(ctx) = context(&test).await? else {
        return Ok(());
    };
    runtime_state::start(&ctx).await?;
    let work = busy::mark(&ctx, "verify").await;
    let draining = tokio::spawn({
        let ctx = ctx.clone();
        async move { runtime_state::drain(&ctx, 30, None, &Shutdown::default()).await }
    });
    tokio::time::sleep(Duration::from_millis(600)).await;
    assert!(!draining.is_finished());
    assert!(ctx.paused().await?);
    drop(work);
    draining.await??;
    test.drop_database().await
}

#[tokio::test]
async fn stop_lasts_until_start_and_clears_a_drain() -> Result<()> {
    let Some(test) = TestDb::create().await? else {
        return Ok(());
    };
    let Some(ctx) = context(&test).await? else {
        return Ok(());
    };
    let signals = Shutdown::default();
    runtime_state::start(&ctx).await?;
    runtime_state::drain(&ctx, 5, Some("deploy-1"), &signals).await?;
    let work = busy::mark(&ctx, "ingest").await;
    let remaining = runtime_state::stop(&ctx, false, 0, &signals).await?;
    assert_eq!(remaining, ["ingest"]);
    assert_eq!(flags(&ctx).await?, (false, true));
    assert!(runtime_state::resume(&ctx, None).await.is_err());
    drop(work);
    idle(&ctx).await?;
    runtime_state::start(&ctx).await?;
    assert_eq!(flags(&ctx).await?, (true, false));
    test.drop_database().await
}

#[tokio::test]
async fn a_bad_token_changes_nothing() -> Result<()> {
    let Some(test) = TestDb::create().await? else {
        return Ok(());
    };
    let Some(ctx) = context(&test).await? else {
        return Ok(());
    };
    runtime_state::start(&ctx).await?;
    assert!(
        runtime_state::drain(&ctx, 5, Some("not valid"), &Shutdown::default())
            .await
            .is_err()
    );
    assert_eq!(flags(&ctx).await?, (true, false));
    test.drop_database().await
}

#[tokio::test]
async fn the_health_check_follows_the_heartbeat_only_while_running() -> Result<()> {
    let Some(test) = TestDb::create().await? else {
        return Ok(());
    };
    let Some(ctx) = context(&test).await? else {
        return Ok(());
    };
    let mut redis = ctx.redis.clone();
    assert!(
        runtime_state::health_check(&ctx)
            .await?
            .contains("intentionally")
    );
    runtime_state::start(&ctx).await?;
    assert!(runtime_state::health_check(&ctx).await.is_err());
    let _: () = redis
        .set(heartbeat_key(&ctx), Utc::now().timestamp())
        .await?;
    assert!(runtime_state::health_check(&ctx).await?.contains("alive"));
    let _: () = redis
        .set(heartbeat_key(&ctx), Utc::now().timestamp() - 100_000)
        .await?;
    assert!(runtime_state::health_check(&ctx).await.is_err());
    runtime_state::stop(&ctx, false, 0, &Shutdown::default()).await?;
    assert!(
        runtime_state::health_check(&ctx)
            .await?
            .contains("intentionally")
    );
    let _: () = redis.del(heartbeat_key(&ctx)).await?;
    test.drop_database().await
}
